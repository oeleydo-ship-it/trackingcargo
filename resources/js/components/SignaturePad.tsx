import { useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';

interface SignaturePadProps {
    onChange: (file: File | null) => void;
}

export default function SignaturePad({ onChange }: SignaturePadProps) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const [hasDrawn, setHasDrawn] = useState(false);

    const context = () => canvasRef.current?.getContext('2d') ?? null;

    const point = (event: ReactPointerEvent<HTMLCanvasElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };

    const start = (event: ReactPointerEvent<HTMLCanvasElement>) => {
        drawing.current = true;
        const ctx = context();
        const { x, y } = point(event);
        ctx?.beginPath();
        ctx?.moveTo(x, y);
    };

    const draw = (event: ReactPointerEvent<HTMLCanvasElement>) => {
        if (!drawing.current) {
            return;
        }
        const ctx = context();
        if (!ctx) {
            return;
        }
        const { x, y } = point(event);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#0f172a';
        ctx.lineTo(x, y);
        ctx.stroke();
        setHasDrawn(true);
    };

    const finish = () => {
        if (!drawing.current) {
            return;
        }
        drawing.current = false;
        canvasRef.current?.toBlob((blob) => {
            onChange(blob ? new File([blob], 'signature.png', { type: 'image/png' }) : null);
        }, 'image/png');
    };

    const clear = () => {
        const canvas = canvasRef.current;
        const ctx = context();
        if (canvas && ctx) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
        setHasDrawn(false);
        onChange(null);
    };

    return (
        <div>
            <canvas
                ref={canvasRef}
                width={400}
                height={160}
                onPointerDown={start}
                onPointerMove={draw}
                onPointerUp={finish}
                onPointerLeave={finish}
                className="w-full touch-none rounded-xl border border-white/10 bg-white"
            />
            <div className="mt-2 flex items-center justify-between">
                <p className="text-xs text-slate-500">Sign above with mouse or finger</p>
                {hasDrawn && (
                    <button type="button" onClick={clear} className="text-xs text-rose-400 hover:text-rose-300">Clear</button>
                )}
            </div>
        </div>
    );
}
