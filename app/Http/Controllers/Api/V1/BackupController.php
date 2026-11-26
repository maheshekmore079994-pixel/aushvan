<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MakeBackup;
use App\Domain\Backup\BackupFolder;
use App\Domain\Shared\Audit;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Settings → Backup. Every route needs backup.manage (checked in routes/api.php). */
class BackupController extends Controller
{
    public function __construct(private BackupFolder $folder) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->overview($request));
    }

    /** "Back up now." */
    public function store(Request $request, MakeBackup $make): JsonResponse
    {
        $backup = $make->handle($request->user()->store, $request->user(), MakeBackup::MANUAL);

        return response()->json($this->present($backup), 201);
    }

    /**
     * Called by the home screen when a backup folder is set and no verified copy went there recently.
     * Answers 204 when nothing needs doing, so an open dashboard never makes two in a row.
     */
    public function auto(Request $request, MakeBackup $make): JsonResponse|Response
    {
        $store = $request->user()->store;
        if (! $this->folder->status($store)['auto_wanted']) {
            return response()->noContent();
        }
        try {
            $backup = $make->handle($store, $request->user(), MakeBackup::AUTO);
        } catch (BusinessRuleException $e) {
            if ($e->ruleCode === 'backup_running') {
                return response()->noContent(); // another screen is already doing it
            }
            throw $e;
        }

        return response()->json($this->present($backup), 201);
    }

    public function download(Request $request, Backup $backup): BinaryFileResponse
    {
        abort_unless($backup->store_id === $request->user()->store_id, 404);
        $localDir = realpath((string) config('shop.backup.local_dir'));
        $file = $backup->local_path !== null ? realpath($backup->local_path) : false;
        if (! $backup->hasLocalFile() || $file === false || $localDir === false || ! str_starts_with($file, $localDir.DIRECTORY_SEPARATOR)) {
            throw new BusinessRuleException('backup_file_gone', 'This backup is no longer on this computer.');
        }
        Audit::log('backup.downloaded', $backup);

        return response()->download($file, $backup->file_name, ['Content-Type' => 'application/zip']);
    }

    /** Sets or clears the backup folder (USB stick / second disk). */
    public function updateFolder(Request $request): JsonResponse
    {
        $data = $request->validate(['folder' => ['nullable', 'string', 'max:255']]);
        $store = $request->user()->store;
        $path = isset($data['folder']) ? trim($data['folder']) : '';
        if ($path !== '') {
            $path = $this->folder->normalise($path);
            if (($problem = $this->folder->problem($path)) !== null) {
                // The code is the message: the screen shows it as errors.<code> in the user's language.
                throw ValidationException::withMessages(['folder' => $problem]);
            }
        }
        $before = $this->folder->path($store);
        $store->putSetting(BackupFolder::SETTING, $path === '' ? null : $path);
        if ($before !== ($path === '' ? null : $path)) {
            Audit::log('backup.folder_changed', $store, ['folder' => $before], ['folder' => $path === '' ? null : $path]);
        }

        return response()->json($this->overview($request));
    }

    /** @return array<string, mixed> */
    private function overview(Request $request): array
    {
        $store = $request->user()->store->refresh();
        $backups = Backup::where('store_id', $store->id)->with('creator:id,name')->orderByDesc('id')->limit(30)->get();

        return [
            'status' => $this->folder->status($store),
            'drives' => $this->folder->drives(),
            'keep_local' => (int) config('shop.backup.keep_local'),
            'backups' => $backups->map(fn (Backup $b) => $this->present($b))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Backup $backup): array
    {
        $backup->loadMissing('creator:id,name');

        return [
            'id' => $backup->id,
            'kind' => $backup->kind,
            'file_name' => $backup->file_name,
            'size_bytes' => $backup->size_bytes,
            'status' => $backup->status,
            'error' => $backup->error,
            'warning' => $backup->warning,
            'folder_path' => $backup->folder_path,
            'downloadable' => $backup->hasLocalFile(),
            'counts' => $backup->manifest['counts'] ?? null,
            'by' => $backup->creator?->name,
            'created_at' => $backup->created_at->toIso8601String(),
        ];
    }
}
