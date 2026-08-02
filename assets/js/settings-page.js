(() => {
    const feedbackMessages = document.querySelectorAll('[data-settings-feedback]');

    feedbackMessages.forEach((message) => {
        window.setTimeout(() => {
            message.classList.add('is-dismissing');
            window.setTimeout(() => message.remove(), 300);
        }, 5000);
    });

    const photoInput = document.querySelector('[data-settings-photo-input]');
    const photoName = document.querySelector('[data-settings-photo-name]');

    if (!photoInput || !photoName) {
        return;
    }

    photoInput.addEventListener('change', () => {
        const selectedPhoto = photoInput.files && photoInput.files[0];
        photoName.textContent = selectedPhoto ? selectedPhoto.name : 'No photo selected';
    });
})();
