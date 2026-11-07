/* global Swiper */
// Galería de producto + lightbox con zoom. Sin dependencias salvo Swiper (bundle global).
// Contrato: atributos data-* de templates/gallery.html. Si falta algo requerido, no inicializa
// y la página queda con las imágenes estáticas.

const GALLERY_MAX_ZOOM = 5;
const GALLERY_ZOOM_STEP = 0.5;

function initProductGallery(root) {
  const mainElement = root.querySelector('[data-gallery-main]');
  const thumbsElement = root.querySelector('[data-gallery-thumbs]');
  const modal = root.querySelector('[data-gallery-modal]');
  const modalElement = root.querySelector('[data-gallery-modal-slider]');

  if (!(mainElement instanceof HTMLElement)
    || !(modal instanceof HTMLDialogElement)
    || !(modalElement instanceof HTMLElement)) {
    return;
  }

  const thumbsSwiper = thumbsElement instanceof HTMLElement
    ? new Swiper(thumbsElement, {
        slidesPerView: 'auto',
        spaceBetween: 8,
        watchSlidesProgress: true, // requerido por Thumbs para marcar la miniatura activa
        freeMode: true,
      })
    : null;

  const mainSwiper = new Swiper(mainElement, {
    spaceBetween: 0,
    ...(thumbsSwiper ? { thumbs: { swiper: thumbsSwiper } } : {}),
  });

  // Un objeto `zoom` activa el módulo. Observer: el slider vive en un <dialog> cerrado (0×0).
  const modalSwiper = new Swiper(modalElement, {
    zoom: { maxRatio: GALLERY_MAX_ZOOM, minRatio: 1 },
    observer: true,
    observeParents: true,
    resizeObserver: true,
  });

  const closeButton = modal.querySelector('[data-gallery-close]:not([aria-hidden])');
  const previousButton = modal.querySelector('[data-gallery-prev]');
  const nextButton = modal.querySelector('[data-gallery-next]');
  const counter = modal.querySelector('[data-gallery-counter]');

  function updateModalState() {
    const total = modalSwiper.slides.length;
    if (counter) counter.textContent = `${modalSwiper.activeIndex + 1} / ${total}`;

    if (previousButton instanceof HTMLButtonElement) {
      previousButton.disabled = modalSwiper.isBeginning;
    }
    if (nextButton instanceof HTMLButtonElement) {
      nextButton.disabled = modalSwiper.isEnd;
    }
  }

  function openGallery() {
    if (modal.open) return;

    modal.showModal();
    // Doble rAF: esperar a que el <dialog> tenga tamaño antes de medir el slider.
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        modalSwiper.update();
        modalSwiper.slideTo(mainSwiper.activeIndex, 0);
        modalSwiper.zoom.out();
        updateModalState();
        if (closeButton instanceof HTMLButtonElement) closeButton.focus();
      });
    });
  }

  // Toda vía de cierre pasa por aquí para mantener sincronizado el índice con el principal.
  function syncAndCloseGallery() {
    modalSwiper.zoom.out();
    mainSwiper.slideTo(modalSwiper.activeIndex, 0);
    if (modal.open) modal.close();
    mainElement.focus({ preventScroll: true });
  }

  function changeModalSlide(direction) {
    modalSwiper.zoom.out();
    if (direction === 'next') {
      modalSwiper.slideNext();
      return;
    }
    modalSwiper.slidePrev();
  }

  mainElement.addEventListener('click', openGallery);
  mainElement.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    openGallery();
  });

  modal.querySelectorAll('[data-gallery-close]').forEach((element) => {
    element.addEventListener('click', syncAndCloseGallery);
  });

  previousButton?.addEventListener('click', (event) => {
    event.stopPropagation();
    changeModalSlide('previous');
  });
  nextButton?.addEventListener('click', (event) => {
    event.stopPropagation();
    changeModalSlide('next');
  });

  // Esc nativo cerraría el <dialog> sin sincronizar el índice.
  modal.addEventListener('cancel', (event) => {
    event.preventDefault();
    syncAndCloseGallery();
  });

  document.addEventListener('keydown', (event) => {
    if (!modal.open) return;
    if (event.key === 'ArrowRight') changeModalSlide('next');
    if (event.key === 'ArrowLeft') changeModalSlide('previous');
  });

  // Rueda = zoom (passive:false para poder evitar el scroll de fondo).
  modal.addEventListener('wheel', (event) => {
    if (!modal.open) return;
    event.preventDefault();

    const currentScale = modalSwiper.zoom.scale ?? 1;
    const delta = event.deltaY < 0 ? GALLERY_ZOOM_STEP : -GALLERY_ZOOM_STEP;
    const nextScale = Math.min(GALLERY_MAX_ZOOM, Math.max(1, currentScale + delta));

    if (nextScale === 1) {
      modalSwiper.zoom.out();
      return;
    }
    modalSwiper.zoom.in(nextScale);
  }, { passive: false });

  modalSwiper.on('slideChange', () => {
    modalSwiper.zoom.out();
    updateModalState();
  });

  // `.is-loaded` oculta el loader y hace fade-in; también en error para no dejar el spinner infinito.
  root.querySelectorAll('img').forEach((image) => {
    const markLoaded = () => {
      image.classList.add('is-loaded');
      image.closest('.pg-modal-slide')?.classList.add('is-loaded');
      modalSwiper.update();
    };

    if (image.complete) {
      markLoaded();
      return;
    }

    image.addEventListener('load', markLoaded, { once: true });
    image.addEventListener('error', markLoaded, { once: true });
  });

  updateModalState();
}

if (typeof Swiper === 'function') {
  document.querySelectorAll('[data-product-gallery]').forEach(initProductGallery);
}
