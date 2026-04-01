// OTL (One Time Login) functionality
function generateOTLAndLogin(button, serviceId, userEmail, staticLoginUrl) {
    // Disable button and show loading state
    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Generating...';
    
    // Prepare AJAX request
    const formData = new FormData();
    formData.append('action', 'generate_otl');
    formData.append('service_id', serviceId);
    formData.append('user_email', userEmail);
    formData.append('static_login_url', staticLoginUrl);
    
    fetch('/modules/servers/hostedai/lib/ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.login_url) {
            // Success - redirect to OTL URL
            window.open(data.login_url, '_blank');
        } else {
            // Show error message briefly
            if (data.message) {
                button.textContent = data.message;
                setTimeout(() => {
                    button.textContent = originalText;
                    button.disabled = false;
                }, 3000);
            }
            
            // Fallback to static URL if available
            if (data.fallback_url && data.fallback_url !== '#') {
                setTimeout(() => {
                    const fallbackUrl = data.fallback_url.startsWith('http') ? data.fallback_url : 'https://' + data.fallback_url;
                    window.open(fallbackUrl, '_blank');
                }, 3500);
            } else {
                // Re-enable button if no fallback
                setTimeout(() => {
                    button.textContent = originalText;
                    button.disabled = false;
                }, 3000);
            }
        }
    })
    .catch(error => {
        console.error('OTL Generation Error:', error);
        console.error('Error details:', error.message);
        button.textContent = 'Error occurred. Using standard login.';
        
        // Fallback to static URL
        setTimeout(() => {
            if (staticLoginUrl && staticLoginUrl !== '#') {
                const fallbackUrl = staticLoginUrl.startsWith('http') ? staticLoginUrl : 'https://' + staticLoginUrl;
                window.open(fallbackUrl, '_blank');
            } else {
                button.textContent = originalText;
                button.disabled = false;
            }
        }, 2000);
    });
}

// Initialize OTL buttons when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const otlButtons = document.querySelectorAll('.otl-login-btn');
    otlButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const serviceId = this.getAttribute('data-service-id');
            const userEmail = this.getAttribute('data-user-email');
            const staticLoginUrl = this.getAttribute('data-static-url');
            generateOTLAndLogin(this, serviceId, userEmail, staticLoginUrl);
        });
    });
});