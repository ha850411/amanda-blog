// Keep a readable team abbreviation when a remote badge is missing or broken.
document.querySelectorAll('.team-badge').forEach((image) => {
    const showBadge = () => { if (image.naturalWidth > 0) image.classList.add('loaded'); };
    image.addEventListener('load', showBadge);
    const fallback = () => {
        const alternative = image.dataset.fallback;
        if (alternative && image.getAttribute('src') !== alternative) {
            delete image.dataset.fallback;
            image.src = alternative;
        } else {
            image.hidden = true;
        }
    };
    image.addEventListener('error', fallback);
    if (image.complete) image.naturalWidth > 0 ? showBadge() : fallback();
});
