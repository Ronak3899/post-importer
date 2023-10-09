document.querySelector('form').addEventListener('submit', function (event) {
    const urlInput = document.getElementById('website_url');
    const urlValidationMessage = document.getElementById('url_validation_message');
    const urlValue = urlInput.value;

    // Check if the URL starts with "https://" or "http://"
    if (!urlValue.startsWith('https://') && !urlValue.startsWith('http://')) {
        event.preventDefault(); // Prevent form submission
        urlValidationMessage.textContent = 'Website URL must start with "https://" or "http://".';
        urlInput.style.borderColor = 'red';
    } else {
        urlValidationMessage.textContent = ''; // Clear any previous error message
        urlInput.style.borderColor = ''; // Clear the red border
    }
});

