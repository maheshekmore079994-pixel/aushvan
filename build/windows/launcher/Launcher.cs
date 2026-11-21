// AUSHVAN desktop launcher (Windows).
//
// Starts the bundled PHP web server on a local port, prepares the database on first run, opens the
// screens in the default browser, and shows a small window with an Open button and a Stop button.
// Closing the window stops the server. No internet is used. Everything it does is written to
// %LOCALAPPDATA%\AUSHVAN\launcher.log for support.
//
// The shop's data (database, application key) lives in %LOCALAPPDATA%\AUSHVAN\data and survives
// updates and reinstalls; the install folder holds only program files.
//
// Compiled by build/windows/make-desktop.ps1 with the C# compiler that ships with Windows (.NET Framework 4):
//   csc.exe /target:winexe /out:AUSHVAN.exe Launcher.cs
using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Text.RegularExpressions;
using System.Threading;
using System.Windows.Forms;

internal static class Program
{
    private const string ProductName = "AUSHVAN";
    private const int DefaultPort = 8765;

    private static string Root { get { return AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\'); } }
    private static string PhpExe { get { return Path.Combine(Root, "php", "php.exe"); } }
    private static string AppDir { get { return Path.Combine(Root, "app"); } }
    private static string PublicDir { get { return Path.Combine(AppDir, "public"); } }
    private static string LocalDir
    {
        get { return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), ProductName); }
    }
    private static string DataDir { get { return Path.Combine(LocalDir, "data"); } }
    private static string LogFile { get { return Path.Combine(LocalDir, "launcher.log"); } }
    private static string PortFile { get { return Path.Combine(LocalDir, "port.txt"); } }
    private static string KeyFile { get { return Path.Combine(DataDir, "app.key"); } }

    private static Process server;
    private static int port = DefaultPort;
    private static readonly object logLock = new object();

    /** Called on a worker thread when PHP stops after it was up. */
    internal static Action<string> ServerDied;

    [STAThread]
    private static void Main()
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        Directory.CreateDirectory(LocalDir);
        Log("---- launcher started from " + Root);

        if (!File.Exists(PhpExe) || !Directory.Exists(AppDir))
        {
            MessageBox.Show("The installation is incomplete (php or app folder missing). Please reinstall " + ProductName + ".", ProductName, MessageBoxButtons.OK, MessageBoxIcon.Error);
            return;
        }

        // A second copy of the launcher just opens the browser again, on the port the first one chose.
        bool first;
        using (var mutex = new Mutex(true, "Local\\AUSHVAN-launcher", out first))
        {
            if (!first)
            {
                try { port = int.Parse(File.ReadAllText(PortFile).Trim()); } catch { }
                Log("already running: opening the browser only (port " + port + ")");
                OpenBrowser();
                return;
            }
            Application.Run(new MainForm());
        }
    }

    internal static void Log(string text)
    {
        try
        {
            lock (logLock) File.AppendAllText(LogFile, DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "  " + text + "\r\n");
        }
        catch { }
    }

    internal static string Url { get { return "http://127.0.0.1:" + port + "/"; } }

    /** Picks the port, points the application at its data, and makes the database tables on first run. */
    internal static string PrepareDatabase()
    {
        port = FreePort(DefaultPort);
        File.WriteAllText(PortFile, port.ToString());
        Log("port " + port);

        Directory.CreateDirectory(DataDir);
        var dbPath = Path.Combine(DataDir, "aushvan.sqlite");
        if (!File.Exists(dbPath)) File.WriteAllBytes(dbPath, new byte[0]);
        Log("database file " + dbPath);

        var env = Path.Combine(AppDir, ".env");
        var envText = File.Exists(env) ? File.ReadAllText(env) : "";
        envText = SetEnv(envText, "DB_DATABASE", dbPath.Replace("\\", "/"));
        envText = SetEnv(envText, "APP_URL", "http://127.0.0.1:" + port);
        envText = SetEnv(envText, "SANCTUM_STATEFUL_DOMAINS", "127.0.0.1:" + port + ",localhost:" + port + ",127.0.0.1,localhost");
        // The application key is kept with the data: an update that replaces .env must not change it.
        if (File.Exists(KeyFile)) envText = SetEnv(envText, "APP_KEY", File.ReadAllText(KeyFile).Trim());
        File.WriteAllText(env, envText);
        Log("settings written");

        if (!File.Exists(KeyFile))
        {
            var existing = Regex.Match(envText, @"APP_KEY=""?(base64:[^""\r\n]+)");
            if (!existing.Success)
            {
                var key = RunArtisan("key:generate --force --no-interaction");
                if (key.exitCode != 0) return "Could not create the application key.\n" + key.output;
                existing = Regex.Match(File.ReadAllText(env), @"APP_KEY=""?(base64:[^""\r\n]+)");
            }
            if (existing.Success) File.WriteAllText(KeyFile, existing.Groups[1].Value);
        }
        var migrate = RunArtisan("migrate --force --no-interaction");
        if (migrate.exitCode != 0) return "Could not prepare the database.\n" + migrate.output;
        return null;
    }

    private static string SetEnv(string text, string key, string value)
    {
        // Quoted only when needed: artisan's own key:generate looks for APP_KEY=base64:... without quotes.
        var written = value.IndexOfAny(new[] { ' ', '#' }) >= 0 ? "\"" + value + "\"" : value;
        var lines = text.Replace("\r\n", "\n").Split('\n');
        var found = false;
        for (var i = 0; i < lines.Length; i++)
        {
            if (lines[i].StartsWith(key + "="))
            {
                lines[i] = key + "=" + written;
                found = true;
            }
        }
        var result = string.Join("\n", lines);
        if (!found) result = result.TrimEnd('\n') + "\n" + key + "=" + written + "\n";
        return result;
    }

    private struct Result { public int exitCode; public string output; }

    private static Result RunArtisan(string arguments)
    {
        Log("artisan " + arguments);
        var info = new ProcessStartInfo(PhpExe, "\"" + Path.Combine(AppDir, "artisan") + "\" " + arguments)
        {
            WorkingDirectory = AppDir,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        using (var p = Process.Start(info))
        {
            // Both streams read as they arrive: reading one to the end while the other fills would hang.
            var output = new System.Text.StringBuilder();
            p.OutputDataReceived += (s, e) => { if (e.Data != null) lock (output) output.AppendLine(e.Data); };
            p.ErrorDataReceived += (s, e) => { if (e.Data != null) lock (output) output.AppendLine(e.Data); };
            p.BeginOutputReadLine();
            p.BeginErrorReadLine();
            if (!p.WaitForExit(180000))
            {
                try { p.Kill(); } catch { }
                Log("  timed out");
                return new Result { exitCode = -1, output = "artisan " + arguments + " did not finish in 3 minutes.\n" + output };
            }
            p.WaitForExit(); // lets the last output lines arrive
            var text = output.ToString().Trim();
            Log("  exit " + p.ExitCode + (text.Length > 0 ? ": " + text : ""));
            return new Result { exitCode = p.ExitCode, output = text };
        }
    }

    internal static string StartServer()
    {
        var router = Path.Combine(AppDir, "vendor", "laravel", "framework", "src", "Illuminate", "Foundation", "resources", "server.php");
        // Laravel's router serves index.php from the current folder, so PHP runs inside app\public.
        var info = new ProcessStartInfo(PhpExe, "-S 127.0.0.1:" + port + " \"" + router + "\"")
        {
            WorkingDirectory = PublicDir,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        Log("starting php -S on port " + port);
        server = Process.Start(info);
        Log("php process " + server.Id);
        server.OutputDataReceived += (s, e) => { if (e.Data != null) Log("php: " + e.Data); };
        server.ErrorDataReceived += (s, e) => { if (e.Data != null) Log("php: " + e.Data); };
        server.BeginOutputReadLine();
        server.BeginErrorReadLine();
        server.EnableRaisingEvents = true;
        server.Exited += (s, e) =>
        {
            Log("php exited with code " + server.ExitCode);
            var handler = ServerDied;
            if (handler != null) handler("The server stopped (code " + server.ExitCode + "). See " + LogFile);
        };
        KillWithUs(server);
        Log("waiting for the server");

        // First a file PHP serves without starting the application, then one real request (the first one is slow).
        var ready = WaitFor(Url + "icon.svg", 40, 2000) && WaitFor(Url + "api/v1/app/status", 3, 30000);
        if (server.HasExited) return "The server stopped unexpectedly. See " + LogFile;
        return ready ? null : "The server did not answer in time. See " + LogFile;
    }

    private static bool WaitFor(string url, int tries, int timeoutMs)
    {
        for (var i = 0; i < tries; i++)
        {
            if (server.HasExited) return false;
            try
            {
                var request = (HttpWebRequest)WebRequest.Create(url);
                request.Proxy = null; // never through a system proxy for 127.0.0.1
                request.Timeout = timeoutMs;
                request.ReadWriteTimeout = timeoutMs;
                request.KeepAlive = false;
                using (var response = (HttpWebResponse)request.GetResponse())
                {
                    Log("ready: " + url + " -> " + (int)response.StatusCode);
                    return true;
                }
            }
            catch (WebException e)
            {
                if (e.Response != null)
                {
                    Log("answered: " + url + " -> " + (int)((HttpWebResponse)e.Response).StatusCode);
                    return true; // any HTTP answer means PHP is up
                }
                if (i % 8 == 0) Log("waiting: " + e.Status);
                Thread.Sleep(250);
            }
            catch (Exception e)
            {
                Log("wait error: " + e.Message);
                Thread.Sleep(250);
            }
        }
        Log("no answer from " + url);
        return false;
    }

    internal static void StopServer()
    {
        try
        {
            ServerDied = null;
            if (server != null && !server.HasExited) server.Kill();
            Log("server stopped");
        }
        catch { }
    }

    private static int FreePort(int preferred)
    {
        for (var candidate = preferred; candidate < preferred + 20; candidate++)
        {
            try
            {
                var listener = new TcpListener(IPAddress.Loopback, candidate);
                listener.Start();
                listener.Stop();
                return candidate;
            }
            catch (SocketException) { }
        }
        return preferred;
    }

    internal static void OpenBrowser()
    {
        try
        {
            // Edge or Chrome in "app" mode looks like a program window; otherwise the default browser.
            var edge = FindBrowser("Microsoft\\Edge\\Application\\msedge.exe");
            var chrome = FindBrowser("Google\\Chrome\\Application\\chrome.exe");
            var exe = edge ?? chrome;
            if (exe != null) Process.Start(exe, "--app=" + Url + " --window-size=1280,800");
            else Process.Start(new ProcessStartInfo(Url) { UseShellExecute = true });
            Log("browser opened at " + Url);
        }
        catch (Exception e) { Log("browser failed: " + e.Message); }
    }

    private static string FindBrowser(string relative)
    {
        foreach (var folder in new[] { Environment.SpecialFolder.ProgramFilesX86, Environment.SpecialFolder.ProgramFiles, Environment.SpecialFolder.LocalApplicationData })
        {
            var candidate = Path.Combine(Environment.GetFolderPath(folder), relative);
            if (File.Exists(candidate)) return candidate;
        }
        return null;
    }

    // ---- Windows job object: if the launcher dies for any reason (Task Manager, crash), PHP dies with it. ----
    private static IntPtr job = IntPtr.Zero;

    private static void KillWithUs(Process child)
    {
        try
        {
            if (job == IntPtr.Zero)
            {
                job = CreateJobObject(IntPtr.Zero, null);
                var info = new JOBOBJECT_EXTENDED_LIMIT_INFORMATION();
                info.BasicLimitInformation.LimitFlags = 0x2000; // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
                var length = Marshal.SizeOf(typeof(JOBOBJECT_EXTENDED_LIMIT_INFORMATION));
                var ptr = Marshal.AllocHGlobal(length);
                Marshal.StructureToPtr(info, ptr, false);
                if (!SetInformationJobObject(job, 9 /* JobObjectExtendedLimitInformation */, ptr, (uint)length)) Log("job object: could not set limits (" + Marshal.GetLastWin32Error() + ")");
                Marshal.FreeHGlobal(ptr);
            }
            if (!AssignProcessToJobObject(job, child.Handle)) Log("job object: could not assign PHP (" + Marshal.GetLastWin32Error() + ")");
        }
        catch (Exception e) { Log("job object: " + e.Message); }
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern IntPtr CreateJobObject(IntPtr attributes, string name);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool SetInformationJobObject(IntPtr job, int infoClass, IntPtr info, uint length);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);

    [StructLayout(LayoutKind.Sequential)]
    private struct JOBOBJECT_BASIC_LIMIT_INFORMATION
    {
        public long PerProcessUserTimeLimit; public long PerJobUserTimeLimit; public uint LimitFlags; public UIntPtr MinimumWorkingSetSize;
        public UIntPtr MaximumWorkingSetSize; public uint ActiveProcessLimit; public UIntPtr Affinity; public uint PriorityClass; public uint SchedulingClass;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct IO_COUNTERS
    {
        public ulong ReadOperationCount; public ulong WriteOperationCount; public ulong OtherOperationCount;
        public ulong ReadTransferCount; public ulong WriteTransferCount; public ulong OtherTransferCount;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct JOBOBJECT_EXTENDED_LIMIT_INFORMATION
    {
        public JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation; public IO_COUNTERS IoInfo; public UIntPtr ProcessMemoryLimit;
        public UIntPtr JobMemoryLimit; public UIntPtr PeakProcessMemoryUsed; public UIntPtr PeakJobMemoryUsed;
    }
}

internal sealed class MainForm : Form
{
    private readonly Label status = new Label();
    private readonly Button open = new Button();
    private readonly Button stop = new Button();
    private readonly NotifyIcon tray = new NotifyIcon();

    public MainForm()
    {
        Text = "AUSHVAN";
        Icon = SystemIcons.Application;
        try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }
        FormBorderStyle = FormBorderStyle.FixedSingle;
        MaximizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;
        ClientSize = new Size(440, 170);
        Font = new Font("Segoe UI", 10f);
        BackColor = Color.White;

        var title = new Label { Text = "AUSHVAN", Font = new Font("Segoe UI Semibold", 16f), ForeColor = Color.FromArgb(23, 28, 51), Location = new Point(20, 16), AutoSize = true };
        var tagline = new Label { Text = "Every Store. Better Managed.", ForeColor = Color.Gray, Location = new Point(22, 52), AutoSize = true };
        status.Text = "Starting…";
        status.Location = new Point(22, 82);
        status.Size = new Size(400, 24);

        open.Text = "Open AUSHVAN";
        open.Size = new Size(160, 36);
        open.Location = new Point(22, 118);
        open.Enabled = false;
        open.BackColor = Color.FromArgb(67, 83, 224);
        open.ForeColor = Color.White;
        open.FlatStyle = FlatStyle.Flat;
        open.FlatAppearance.BorderSize = 0;
        open.Click += (s, e) => Program.OpenBrowser();

        stop.Text = "Stop and close";
        stop.Size = new Size(140, 36);
        stop.Location = new Point(192, 118);
        stop.FlatStyle = FlatStyle.Flat;
        stop.Click += (s, e) => Close();

        Controls.AddRange(new Control[] { title, tagline, status, open, stop });

        tray.Icon = Icon;
        tray.Text = "AUSHVAN is running";
        tray.Visible = true;
        tray.DoubleClick += (s, e) => { Show(); WindowState = FormWindowState.Normal; };
        Resize += (s, e) => { if (WindowState == FormWindowState.Minimized) Hide(); };

        Shown += (s, e) => StartEverything();
        FormClosing += (s, e) => { tray.Visible = false; Program.StopServer(); };
    }

    private void StartEverything()
    {
        Program.ServerDied = (message) =>
        {
            Report("Stopped: " + message);
            Invoke(new Action(() => { open.Enabled = false; Show(); WindowState = FormWindowState.Normal; }));
        };
        var worker = new Thread(() =>
        {
            string problem = null;
            try
            {
                Report("Preparing the database…");
                problem = Program.PrepareDatabase();
                if (problem == null)
                {
                    Report("Starting the server…");
                    problem = Program.StartServer();
                }
            }
            catch (Exception e)
            {
                problem = e.ToString();
            }
            if (problem != null)
            {
                Program.Log("problem: " + problem);
                Report("Could not start.");
                Invoke(new Action(() => MessageBox.Show(this, problem, "AUSHVAN", MessageBoxButtons.OK, MessageBoxIcon.Error)));
                return;
            }
            Report("Running at " + Program.Url + " — this window can be minimised.");
            Invoke(new Action(() => { open.Enabled = true; }));
            Program.OpenBrowser();
        });
        worker.IsBackground = true;
        worker.Start();
    }

    private void Report(string text)
    {
        if (IsHandleCreated) Invoke(new Action(() => { status.Text = text; }));
    }
}
