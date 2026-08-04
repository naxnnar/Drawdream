/**
 * บีบอัดรูปฝั่งเบราว์เซอร์ก่อนอัปโหลด (Canvas → JPEG)
 */
(function (global) {
    'use strict';

    function canvasToBlob(canvas, type, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) resolve(blob);
                else reject(new Error('compress failed'));
            }, type, quality);
        });
    }

    function loadImageFromFile(file) {
        var url = URL.createObjectURL(file);
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () {
                URL.revokeObjectURL(url);
                resolve(img);
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('load failed'));
            };
            img.src = url;
        });
    }

    function compressedFileName(originalName) {
        var base = String(originalName || 'image').replace(/\.[^.]+$/, '') || 'image';
        return base + '.jpg';
    }

    function isImageFile(file) {
        if (!file) return false;
        if (file.type && file.type.indexOf('image/') === 0) return true;
        return /\.(jpe?g|png|gif|webp|heic|heif|bmp)$/i.test(String(file.name || ''));
    }

    async function compressImageFile(file, maxBytes) {
        if (!file || !isImageFile(file)) {
            throw new Error('not image');
        }

        if (file.size <= maxBytes) {
            return file;
        }

        var target = maxBytes;
        var img = await loadImageFromFile(file);
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d', { alpha: false });
        if (!ctx) {
            throw new Error('no canvas');
        }

        var ratio = file.size / Math.max(1, maxBytes);
        var scale = Math.min(1, 1920 / Math.max(img.naturalWidth, img.naturalHeight, 1));
        if (ratio > 3) scale = Math.min(scale, 0.7);
        if (ratio > 8) scale = Math.min(scale, 0.5);
        if (ratio > 16) scale = Math.min(scale, 0.35);
        if (ratio > 30) scale = Math.min(scale, 0.25);

        var quality = 0.86;
        var lastBlob = null;

        for (var attempt = 0; attempt < 28; attempt++) {
            var w = Math.max(1, Math.round(img.naturalWidth * scale));
            var h = Math.max(1, Math.round(img.naturalHeight * scale));
            canvas.width = w;
            canvas.height = h;
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);

            var blob = await canvasToBlob(canvas, 'image/jpeg', quality);
            lastBlob = blob;
            if (blob.size <= target) {
                return new File([blob], compressedFileName(file.name), {
                    type: 'image/jpeg',
                    lastModified: Date.now(),
                });
            }

            if (quality > 0.28) {
                quality = Math.max(0.28, quality - 0.08);
            } else {
                scale *= 0.72;
                quality = 0.78;
            }
        }

        if (lastBlob && lastBlob.size <= maxBytes) {
            return new File([lastBlob], compressedFileName(file.name), {
                type: 'image/jpeg',
                lastModified: Date.now(),
            });
        }

        return null;
    }

    async function ensureImageWithinLimit(file, maxBytes, serverMaxBytes) {
        if (!file) return null;
        if (!isImageFile(file)) {
            throw new Error('not image');
        }
        if (file.size <= maxBytes) {
            return file;
        }

        var shrunk = null;
        try {
            shrunk = await compressImageFile(file, maxBytes);
        } catch (err) {
            shrunk = null;
        }

        if (shrunk && shrunk.size <= maxBytes) {
            return shrunk;
        }

        var serverCap = serverMaxBytes || maxBytes;
        if (file.size <= serverCap) {
            return file;
        }

        throw new Error('too large');
    }

    global.drawdreamImageCompress = {
        isImageFile: isImageFile,
        compressImageFile: compressImageFile,
        ensureImageWithinLimit: ensureImageWithinLimit,
    };
})(typeof window !== 'undefined' ? window : globalThis);
