// Media gallery public lightbox behavior.

(function() {
    function normalizeItems(items) {
        if (!Array.isArray(items)) {
            return [];
        }
        return items.map((item) => {
            const safe = item && typeof item === 'object' ? item : {};
            return {
                id: String(safe.id || ''),
                type: safe.type === 'video' ? 'video' : 'image',
                file: String(safe.file || ''),
                thumb: String(safe.thumb || ''),
                title: String(safe.title || ''),
                order: Number.isFinite(Number(safe.order)) ? Number(safe.order) : 0
            };
        }).filter((item) => item.id !== '' && item.file !== '');
    }

    function sortItems(items) {
        return items.slice().sort((a, b) => a.order - b.order);
    }

    function initPane(pane) {
        const dataScript = pane.querySelector('.mediaGalleryData');
        let payload = {};
        if (dataScript) {
            try {
                payload = JSON.parse(dataScript.textContent || '{}');
            } catch (err) {
                payload = {};
            }
        }
        const items = sortItems(normalizeItems(payload.items || []));
        const lightbox = pane.querySelector('.mediaGalleryLightbox');
        if (!lightbox) {
            return;
        }
        if (lightbox.parentElement !== document.body) {
            document.body.appendChild(lightbox);
        }
        const lightboxImage = lightbox.querySelector('.mediaGalleryLightboxImage');
        const lightboxVideo = lightbox.querySelector('.mediaGalleryLightboxVideo');
        const caption = lightbox.querySelector('.mediaGalleryLightboxCaption');
        const closeBtn = lightbox.querySelector('.mediaGalleryLightboxClose');
        const prevBtn = lightbox.querySelector('.mediaGalleryLightboxPrev');
        const nextBtn = lightbox.querySelector('.mediaGalleryLightboxNext');
        const backdrop = lightbox.querySelector('[data-lightbox-close]');

        let currentIndex = 0;
        let isOpen = false;
        let touchStartX = 0;
        let touchEndX = 0;

        function updateNav() {
            const hasMultiple = items.length > 1;
            prevBtn.disabled = !hasMultiple;
            nextBtn.disabled = !hasMultiple;
        }

        function showItem(index) {
            if (!items.length) {
                return;
            }
            currentIndex = (index + items.length) % items.length;
            const item = items[currentIndex];
            if (!item) {
                return;
            }
            lightbox.classList.toggle('isVideo', item.type === 'video');
            if (item.type === 'video') {
                if (lightboxImage) {
                    lightboxImage.removeAttribute('src');
                }
                if (lightboxVideo) {
                    lightboxVideo.src = item.file;
                    lightboxVideo.currentTime = 0;
                    lightboxVideo.load();
                }
            } else {
                if (lightboxVideo) {
                    lightboxVideo.pause();
                    lightboxVideo.removeAttribute('src');
                    lightboxVideo.load();
                }
                if (lightboxImage) {
                    lightboxImage.src = item.file;
                    lightboxImage.alt = item.title || '';
                }
            }
            if (caption) {
                caption.textContent = item.title || '';
            }
            updateNav();
        }

        function openLightbox(index) {
            if (!items.length) {
                return;
            }
            isOpen = true;
            lightbox.classList.add('isOpen');
            lightbox.setAttribute('aria-hidden', 'false');
            showItem(index);
        }

        function closeLightbox() {
            if (!isOpen) {
                return;
            }
            isOpen = false;
            lightbox.classList.remove('isOpen');
            lightbox.setAttribute('aria-hidden', 'true');
            if (lightboxVideo) {
                lightboxVideo.pause();
                lightboxVideo.removeAttribute('src');
                lightboxVideo.load();
            }
            if (lightboxImage) {
                lightboxImage.removeAttribute('src');
            }
        }

        function showNext() {
            showItem(currentIndex + 1);
        }

        function showPrev() {
            showItem(currentIndex - 1);
        }

        pane.querySelectorAll('.mediaGalleryPublicItem').forEach((itemEl, index) => {
            itemEl.addEventListener('click', (event) => {
                const isPrimary = event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
                if (!isPrimary) {
                    return;
                }
                event.preventDefault();
                openLightbox(index);
            });
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', () => closeLightbox());
        }
        if (backdrop) {
            backdrop.addEventListener('click', () => closeLightbox());
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', () => showPrev());
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => showNext());
        }

        document.addEventListener('keydown', (event) => {
            if (!isOpen) {
                return;
            }
            if (event.key === 'Escape') {
                closeLightbox();
            } else if (event.key === 'ArrowLeft') {
                showPrev();
            } else if (event.key === 'ArrowRight') {
                showNext();
            }
        });

        lightbox.addEventListener('touchstart', (event) => {
            if (!isOpen || !event.touches || event.touches.length !== 1) {
                return;
            }
            touchStartX = event.touches[0].clientX;
        });

        lightbox.addEventListener('touchend', (event) => {
            if (!isOpen || !event.changedTouches || event.changedTouches.length !== 1) {
                return;
            }
            touchEndX = event.changedTouches[0].clientX;
            const delta = touchEndX - touchStartX;
            if (Math.abs(delta) < 40) {
                return;
            }
            if (delta > 0) {
                showPrev();
            } else {
                showNext();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.mediaGalleryPublic').forEach(initPane);
    });
})();
