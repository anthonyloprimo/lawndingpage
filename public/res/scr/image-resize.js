// Client-side image resize via canvas. Mirror of lawnding_image_resize() so
// admin uploads can produce a derived thumbnail when the server lacks GD.
//
// window.lpImageResize(file, targetW, targetH, mode = 'maxbox', outputType = 'image/webp')
//   → Promise<Blob>
//
// Modes match the server side: maxbox | contain | cover. The dimension math is
// kept structurally identical to lawnding_image_resize_dimensions() so a future
// caller can compare blob bytes against the server's GD output for the same
// inputs and expect matching dimensions (output bytes will differ due to
// browser-vs-GD encoder differences; that's intentional and harmless).

(function () {
    function computeDimensions(srcW, srcH, targetW, targetH, mode) {
        if (srcW <= 0 || srcH <= 0 || targetW <= 0 || targetH <= 0) {
            return null;
        }
        if (mode === 'maxbox') {
            if (srcW <= targetW && srcH <= targetH) {
                return { newW: srcW, newH: srcH, cropX: 0, cropY: 0, cropW: srcW, cropH: srcH };
            }
            const ratio = Math.min(targetW / srcW, targetH / srcH);
            return {
                newW: Math.round(srcW * ratio),
                newH: Math.round(srcH * ratio),
                cropX: 0, cropY: 0, cropW: srcW, cropH: srcH,
            };
        }
        if (mode === 'contain') {
            const ratio = Math.min(targetW / srcW, targetH / srcH);
            return {
                newW: Math.round(srcW * ratio),
                newH: Math.round(srcH * ratio),
                cropX: 0, cropY: 0, cropW: srcW, cropH: srcH,
            };
        }
        if (mode === 'cover') {
            const ratio = Math.max(targetW / srcW, targetH / srcH);
            const cropW = targetW / ratio;
            const cropH = targetH / ratio;
            return {
                newW: targetW,
                newH: targetH,
                cropX: Math.round((srcW - cropW) / 2),
                cropY: Math.round((srcH - cropH) / 2),
                cropW: Math.round(cropW),
                cropH: Math.round(cropH),
            };
        }
        return null;
    }

    function loadImageFromFile(file) {
        return new Promise(function (resolve, reject) {
            const reader = new FileReader();
            reader.onerror = function () { reject(new Error('FileReader failed')); };
            reader.onload = function () {
                const img = new Image();
                img.onerror = function () { reject(new Error('Image decode failed')); };
                img.onload = function () { resolve(img); };
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        });
    }

    window.lpImageResize = function (file, targetW, targetH, mode, outputType) {
        if (!mode) { mode = 'maxbox'; }
        if (!outputType) { outputType = 'image/webp'; }
        return loadImageFromFile(file).then(function (img) {
            const dims = computeDimensions(img.naturalWidth, img.naturalHeight, targetW, targetH, mode);
            if (!dims) {
                return Promise.reject(new Error('Invalid resize parameters'));
            }
            const canvas = document.createElement('canvas');
            canvas.width = dims.newW;
            canvas.height = dims.newH;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return Promise.reject(new Error('Canvas 2D context unavailable'));
            }
            ctx.drawImage(
                img,
                dims.cropX, dims.cropY, dims.cropW, dims.cropH,
                0, 0, dims.newW, dims.newH
            );
            return new Promise(function (resolve, reject) {
                canvas.toBlob(
                    function (blob) {
                        if (blob) { resolve(blob); }
                        else { reject(new Error('Canvas toBlob returned null')); }
                    },
                    outputType,
                    0.85
                );
            });
        });
    };

    // Exposed for direct testing if a future test harness wants to verify the
    // dimension math from JS without going through the canvas/blob path.
    window.lpImageResize.computeDimensions = computeDimensions;
})();
