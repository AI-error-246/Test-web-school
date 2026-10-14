import { createRoot } from 'react-dom/client';
import { SylvaLivingWorldScene } from '../vendor/threeui-community/src/shaders/sylva-living-world/SylvaLivingWorldScene';
import '../vendor/threeui-community/src/shaders/community.css';

export function mountScene(host: HTMLElement) {
    const root = createRoot(host);
    root.render(
        <div className="shader-frame">
            <SylvaLivingWorldScene variant="living-green" />
        </div>,
    );
    return () => root.unmount();
}
