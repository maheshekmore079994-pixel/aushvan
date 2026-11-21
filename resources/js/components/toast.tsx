import { CheckCircle2, XCircle } from 'lucide-react';
import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';

type Toast = { id: number; tone: 'success' | 'error'; text: string };

const ToastContext = createContext<(tone: Toast['tone'], text: string) => void>(() => {});

/** Short confirmation in the corner ("Saved"). Errors stay longer. */
export function ToastProvider({ children }: { children: ReactNode }) {
    const [toasts, setToasts] = useState<Toast[]>([]);

    const show = useCallback((tone: Toast['tone'], text: string) => {
        const id = Date.now() + Math.random();
        setToasts((current) => [...current, { id, tone, text }]);
        setTimeout(() => setToasts((current) => current.filter((t) => t.id !== id)), tone === 'error' ? 7000 : 3000);
    }, []);

    return (
        <ToastContext.Provider value={show}>
            {children}
            <div className="pointer-events-none fixed bottom-20 right-3 z-50 lg:bottom-4 lg:right-4 flex flex-col gap-2" aria-live="polite">
                {toasts.map((toast) => (
                    <div
                        key={toast.id}
                        className={`animate-slide-in pointer-events-auto flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-white shadow-pop ${
                            toast.tone === 'success' ? 'bg-success' : 'bg-danger'
                        }`}
                    >
                        {toast.tone === 'success' ? <CheckCircle2 className="size-5" aria-hidden /> : <XCircle className="size-5" aria-hidden />}
                        {toast.text}
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export const useToast = () => useContext(ToastContext);
