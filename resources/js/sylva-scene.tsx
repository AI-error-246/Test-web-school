import { createRoot } from 'react-dom/client';
import { SylvaLivingWorldScene } from '@designcodeio/threeui';
import '@designcodeio/threeui/style.css';

export function Scene() {
    return (
        <div className="shader-frame">
            <SylvaLivingWorldScene variant="living-green" />
        </div>
    );
}

export function mountScene(host: HTMLElement) {
    const root = createRoot(host);
    root.render(<Scene />);
    return () => root.unmount();
}
