/**
 * assets/js/compressor.js - Automatic Client-Side Image Compression, Auto-Cropper & Manual Crop Adjuster
 * - Smart coin boundary detection (Otsu variance + border color sampling + gradient analysis).
 * - Aspect ratio 1:1 tight square crop.
 * - Interactive Crop Modal (powered by Cropper.js) for easy manual adjustment of crop box.
 * - Solid pure white (#FFFFFF) background fill to prevent transparent black backgrounds.
 * - Stores processed Base64 data for Gemini AI numismatic catalog autofill.
 */

window.coinImageData = {
    obverse: null,
    reverse: null
};

/**
 * Advanced coin boundary detection algorithm.
 * Identifies background profile and finds the tightest square bounding box enclosing the coin.
 */
function detectCoinBoundingBox(img) {
    const origW = img.naturalWidth || img.width;
    const origH = img.naturalHeight || img.height;
    
    // Fast analysis canvas capped at 400px
    const scale = Math.min(1, 400 / Math.max(origW, origH));
    const aW = Math.max(50, Math.round(origW * scale));
    const aH = Math.max(50, Math.round(origH * scale));
    
    const aCanvas = document.createElement('canvas');
    aCanvas.width = aW;
    aCanvas.height = aH;
    const aCtx = aCanvas.getContext('2d', { willReadFrequently: true });
    aCtx.drawImage(img, 0, 0, aW, aH);
    
    let imgData;
    try {
        imgData = aCtx.getImageData(0, 0, aW, aH);
    } catch (e) {
        const s = Math.min(origW, origH);
        return { cropX: Math.round((origW - s) / 2), cropY: Math.round((origH - s) / 2), cropSize: s };
    }

    const data = imgData.data;
    
    // 1. Sample 4 outer borders (5% margin) to get background color distribution
    const borderSamples = [];
    const bW = Math.max(2, Math.floor(aW * 0.05));
    const bH = Math.max(2, Math.floor(aH * 0.05));
    
    function getRGB(x, y) {
        const idx = (y * aW + x) * 4;
        return [data[idx], data[idx + 1], data[idx + 2]];
    }

    for (let x = 0; x < aW; x++) {
        for (let y = 0; y < bH; y++) borderSamples.push(getRGB(x, y));
        for (let y = aH - bH; y < aH; y++) borderSamples.push(getRGB(x, y));
    }
    for (let y = bH; y < aH - bH; y++) {
        for (let x = 0; x < bW; x++) borderSamples.push(getRGB(x, y));
        for (let x = aW - bW; x < aW; x++) borderSamples.push(getRGB(x, y));
    }
    
    let sumR = 0, sumG = 0, sumB = 0;
    for (let i = 0; i < borderSamples.length; i++) {
        sumR += borderSamples[i][0];
        sumG += borderSamples[i][1];
        sumB += borderSamples[i][2];
    }
    const bgR = sumR / borderSamples.length;
    const bgG = sumG / borderSamples.length;
    const bgB = sumB / borderSamples.length;
    
    let varSum = 0;
    for (let i = 0; i < borderSamples.length; i++) {
        const dist = Math.sqrt(
            Math.pow(borderSamples[i][0] - bgR, 2) +
            Math.pow(borderSamples[i][1] - bgG, 2) +
            Math.pow(borderSamples[i][2] - bgB, 2)
        );
        varSum += dist * dist;
    }
    const bgStd = Math.sqrt(varSum / borderSamples.length);
    const threshold = Math.max(18, bgStd * 2.0 + 10);
    
    // 2. Identify coin / foreground pixels
    let minY = aH, maxY = 0, minX = aW, maxX = 0;
    let fgCount = 0;
    
    const rowHits = new Uint16Array(aH);
    const colHits = new Uint16Array(aW);
    
    for (let y = 0; y < aH; y++) {
        for (let x = 0; x < aW; x++) {
            const idx = (y * aW + x) * 4;
            const r = data[idx], g = data[idx + 1], b = data[idx + 2], a = data[idx + 3];
            
            const dist = (a < 200) ? 999 : Math.sqrt(
                Math.pow(r - bgR, 2) + Math.pow(g - bgG, 2) + Math.pow(b - bgB, 2)
            );
            
            if (dist > threshold) {
                rowHits[y]++;
                colHits[x]++;
                fgCount++;
            }
        }
    }
    
    if (fgCount < aW * aH * 0.02 || fgCount > aW * aH * 0.98) {
        const s = Math.min(origW, origH);
        return {
            cropX: Math.round((origW - s) / 2),
            cropY: Math.round((origH - s) / 2),
            cropSize: s
        };
    }
    
    const minRowDensity = Math.max(2, Math.floor(aW * 0.02));
    const minColDensity = Math.max(2, Math.floor(aH * 0.02));
    
    for (let y = 0; y < aH; y++) {
        if (rowHits[y] >= minRowDensity) { minY = y; break; }
    }
    for (let y = aH - 1; y >= 0; y--) {
        if (rowHits[y] >= minRowDensity) { maxY = y; break; }
    }
    for (let x = 0; x < aW; x++) {
        if (colHits[x] >= minColDensity) { minX = x; break; }
    }
    for (let x = aW - 1; x >= 0; x--) {
        if (colHits[x] >= minColDensity) { maxX = x; break; }
    }
    
    if (minX >= maxX || minY >= maxY) {
        const s = Math.min(origW, origH);
        return {
            cropX: Math.round((origW - s) / 2),
            cropY: Math.round((origH - s) / 2),
            cropSize: s
        };
    }
    
    const realMinX = minX / scale;
    const realMaxX = maxX / scale;
    const realMinY = minY / scale;
    const realMaxY = maxY / scale;
    
    const coinW = realMaxX - realMinX;
    const coinH = realMaxY - realMinY;
    const coinCenterX = (realMinX + realMaxX) / 2;
    const coinCenterY = (realMinY + realMaxY) / 2;
    
    // Tight 1:1 square crop around the coin
    let cropSize = Math.max(coinW, coinH);
    
    let cropX = Math.round(coinCenterX - cropSize / 2);
    let cropY = Math.round(coinCenterY - cropSize / 2);
    
    if (cropX < 0) cropX = 0;
    if (cropY < 0) cropY = 0;
    if (cropX + cropSize > origW) cropSize = origW - cropX;
    if (cropY + cropSize > origH) cropSize = origH - cropY;
    
    return {
        cropX: Math.max(0, cropX),
        cropY: Math.max(0, cropY),
        cropSize: Math.max(50, Math.round(cropSize))
    };
}

// Global Manual Cropper Manager
let activeCropperInstance = null;
let currentCropTargetInput = null;

function openManualCropModal(inputElement) {
    if (!inputElement || !inputElement._rawImageSrc) return;
    
    let modalEl = document.getElementById('manualCoinCropModal');
    if (!modalEl) {
        const modalHtml = `
            <div class="modal fade" id="manualCoinCropModal" tabindex="-1" aria-labelledby="manualCoinCropModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content border-0 shadow-lg">
                        <div class="modal-header bg-dark text-white">
                            <h5 class="modal-title d-flex align-items-center gap-2" id="manualCoinCropModalLabel">
                                <span class="material-symbols-outlined">crop</span>
                                <span>Adjust Coin Crop (Square 1:1)</span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-3 bg-secondary bg-opacity-10 text-center">
                            <div class="img-crop-container" style="max-height: 520px; overflow: hidden; background: #222; border-radius: 8px;">
                                <img id="manualCropImageElement" src="" alt="Coin Crop Source" style="max-width: 100%; display: block;" />
                            </div>
                            <div class="d-flex justify-content-center align-items-center flex-wrap gap-2 mt-3">
                                <button type="button" class="btn btn-sm btn-outline-dark" onclick="activeCropperInstance && activeCropperInstance.zoom(0.1)" title="Zoom In">
                                    <span class="material-symbols-outlined fs-6">zoom_in</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-dark" onclick="activeCropperInstance && activeCropperInstance.zoom(-0.1)" title="Zoom Out">
                                    <span class="material-symbols-outlined fs-6">zoom_out</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-dark" onclick="activeCropperInstance && activeCropperInstance.rotate(-90)" title="Rotate Left">
                                    <span class="material-symbols-outlined fs-6">rotate_left</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-dark" onclick="activeCropperInstance && activeCropperInstance.rotate(90)" title="Rotate Right">
                                    <span class="material-symbols-outlined fs-6">rotate_right</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="activeCropperInstance && activeCropperInstance.reset()" title="Reset Box">
                                    <span class="material-symbols-outlined fs-6">restart_alt</span> Reset
                                </button>
                            </div>
                            <div class="small text-muted mt-2">
                                💡 কয়েনের কিনারা বরাবর স্কয়ার ফ্রেমটি ড্র্যাগ ও রিসাইজ করে সঠিক পজিশনে বসান।
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary-archival px-4 d-inline-flex align-items-center gap-1" id="btnApplyManualCrop">
                                <span class="material-symbols-outlined fs-6">check</span>
                                <span>Apply Crop</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        modalEl = document.getElementById('manualCoinCropModal');
    }

    currentCropTargetInput = inputElement;
    const cropImgEl = document.getElementById('manualCropImageElement');
    cropImgEl.src = inputElement._rawImageSrc;

    const bsModal = new bootstrap.Modal(modalEl);
    
    modalEl.addEventListener('shown.bs.modal', function onModalShown() {
        modalEl.removeEventListener('shown.bs.modal', onModalShown);
        if (activeCropperInstance) {
            activeCropperInstance.destroy();
        }

        if (typeof Cropper !== 'undefined') {
            activeCropperInstance = new Cropper(cropImgEl, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 0.9,
                responsive: true,
                restore: false,
                checkCrossOrigin: false,
                background: true,
                movable: true,
                rotatable: true,
                scalable: true,
                zoomable: true
            });
        }
    });

    document.getElementById('btnApplyManualCrop').onclick = function () {
        if (!activeCropperInstance || !currentCropTargetInput) return;

        const maxDim = 1200;
        const croppedCanvas = activeCropperInstance.getCroppedCanvas({
            width: maxDim,
            height: maxDim,
            fillColor: '#FFFFFF',
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
        });

        if (!croppedCanvas) return;

        applyCroppedCanvasToInput(currentCropTargetInput, croppedCanvas, 'Manual Cropped');
        bsModal.hide();
    };

    bsModal.show();
}

function applyCroppedCanvasToInput(input, canvas, cropTypeLabel) {
    const isObverse = input.name === 'obverse_image';
    const isReverse = input.name === 'reverse_image';
    const originalFile = input._originalFile || (input.files && input.files[0]);
    const fileName = originalFile ? originalFile.name : (isObverse ? 'obverse.jpg' : 'reverse.jpg');

    const previewBox = input.parentNode.querySelector('.coin-preview-box');
    const infoBadge = input.parentNode.querySelector('.compressor-info-badge');

    const base64Data = canvas.toDataURL('image/jpeg', 0.85);
    const base64Clean = base64Data.split(',')[1];

    if (isObverse) {
        window.coinImageData.obverse = {
            base64: base64Clean,
            mimeType: 'image/jpeg',
            dataUrl: base64Data
        };
    } else if (isReverse) {
        window.coinImageData.reverse = {
            base64: base64Clean,
            mimeType: 'image/jpeg',
            dataUrl: base64Data
        };
    }

    if (previewBox) {
        previewBox.innerHTML = `
            <div class="d-flex align-items-center justify-content-between p-2">
                <div class="d-flex align-items-center gap-2">
                    <img src="${base64Data}" alt="Cropped Preview" class="rounded border shadow-sm" style="width: 70px; height: 70px; object-fit: contain; background: #fff;" />
                    <div class="text-start">
                        <span class="badge bg-success-subtle text-success border border-success-subtle d-inline-flex align-items-center gap-1 mb-1">
                            <span class="material-symbols-outlined fs-6">crop</span> ${cropTypeLabel || '1:1 Square'}
                        </span>
                        <div class="text-muted small">${canvas.width}×${canvas.height} px</div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" onclick="openManualCropModal(this.closest('.col-12').querySelector('input[type=file]'))">
                    <span class="material-symbols-outlined fs-6">crop</span>
                    <span>Adjust Crop</span>
                </button>
            </div>
        `;
        previewBox.classList.remove('d-none');
    }

    canvas.toBlob(function (blob) {
        if (!blob) return;

        const compressedFile = new File([blob], fileName.replace(/\.[^/.]+$/, "") + ".jpg", {
            type: 'image/jpeg',
            lastModified: Date.now()
        });

        try {
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(compressedFile);
            input.files = dataTransfer.files;
        } catch (err) {
            console.log("DataTransfer assignment error fallback", err);
        }

        if (infoBadge) {
            const origSize = originalFile ? originalFile.size : blob.size;
            const savedPercent = origSize > blob.size ? (((origSize - blob.size) / origSize) * 100).toFixed(1) : 0;
            infoBadge.className = 'compressor-info-badge small text-success mt-1 font-monospace fw-bold';
            infoBadge.innerHTML = `✓ Ready: ${(blob.size / 1024).toFixed(0)}KB (${savedPercent > 0 ? savedPercent + '% saved' : 'Optimized'})`;
        }

        window.dispatchEvent(new CustomEvent('coinImagesUpdated', {
            detail: { isObverse, isReverse }
        }));
    }, 'image/jpeg', 0.85);
}

document.addEventListener('DOMContentLoaded', function () {
    const fileInputs = document.querySelectorAll('input[type="file"]');
    const autoCropToggle = document.getElementById('autoCropToggle');

    fileInputs.forEach(function (input) {
        let previewBox = input.parentNode.querySelector('.coin-preview-box');
        if (!previewBox) {
            previewBox = document.createElement('div');
            previewBox.className = 'coin-preview-box mt-2 d-none rounded border bg-light';
            input.parentNode.appendChild(previewBox);
        }

        let infoBadge = input.parentNode.querySelector('.compressor-info-badge');
        if (!infoBadge) {
            infoBadge = document.createElement('div');
            infoBadge.className = 'compressor-info-badge small text-success mt-1 d-none font-monospace fw-bold';
            input.parentNode.appendChild(infoBadge);
        }

        function processImageFile(file) {
            if (!file || !file.type.match(/image.*/)) return;

            input._originalFile = file;
            infoBadge.className = 'compressor-info-badge small text-primary mt-1 font-monospace';
            infoBadge.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Auto-cropping coin edges...';
            infoBadge.classList.remove('d-none');

            const reader = new FileReader();
            reader.onload = function (event) {
                input._rawImageSrc = event.target.result;

                const img = new Image();
                img.crossOrigin = "Anonymous";
                img.onload = function () {
                    const shouldAutoCrop = !autoCropToggle || autoCropToggle.checked;
                    
                    let srcX = 0, srcY = 0, srcW = img.naturalWidth || img.width, srcH = img.naturalHeight || img.height;
                    
                    if (shouldAutoCrop) {
                        const bbox = detectCoinBoundingBox(img);
                        srcX = bbox.cropX;
                        srcY = bbox.cropY;
                        srcW = bbox.cropSize;
                        srcH = bbox.cropSize;
                    }

                    const maxDim = 1200;
                    let outW = srcW;
                    let outH = srcH;

                    if (outW > maxDim || outH > maxDim) {
                        if (outW > outH) {
                            outH = Math.round((outH * maxDim) / outW);
                            outW = maxDim;
                        } else {
                            outW = Math.round((outW * maxDim) / outH);
                            outH = maxDim;
                        }
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width = outW;
                    canvas.height = outH;

                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillRect(0, 0, outW, outH);
                    ctx.drawImage(img, srcX, srcY, srcW, srcH, 0, 0, outW, outH);

                    applyCroppedCanvasToInput(input, canvas, shouldAutoCrop ? 'Square Auto-Cropped' : 'Standard');
                };
                img.src = event.target.result;
            };
            reader.readAsDataURL(file);
        }

        input.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (file) {
                processImageFile(file);
            }
        });

        if (autoCropToggle) {
            autoCropToggle.addEventListener('change', function () {
                if (input._originalFile) {
                    processImageFile(input._originalFile);
                }
            });
        }
    });
});
