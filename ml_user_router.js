// ml_user_router.js
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('user-select-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            var select = document.getElementById('user_id');
            if (!select || !select.value) {
                e.preventDefault();
                alert('Please select your name.');
                return;
            }

            var userId = select.value;

            // Store in localStorage for a long time (best effort)
            try {
                localStorage.setItem('ml_user_id', String(userId));
            } catch (err) {
                // ignore if blocked
            }

            // Also store in cookie so PHP can see it
            var expires = new Date();
            expires.setFullYear(expires.getFullYear() + 5); // 5 years
            document.cookie =
                'ml_user_id=' + encodeURIComponent(userId) +
                ';expires=' + expires.toUTCString() +
                ';path=/';

            // IMPORTANT:
            // Do NOT prevent default here.
            // Let the form submit normally to index.php (POST),
            // and PHP will handle redirecting to questions/choice/final.
        });
    }

    // Optional: if localStorage already has a user_id but there's no cookie yet,
    // set the cookie so future visits to the *root* can auto-route.
    var storedId = null;
    try {
        storedId = localStorage.getItem('ml_user_id');
    } catch (err) {
        storedId = null;
    }

    if (storedId && !getCookie('ml_user_id')) {
        var expires = new Date();
        expires.setFullYear(expires.getFullYear() + 5);
        document.cookie =
            'ml_user_id=' + encodeURIComponent(storedId) +
            ';expires=' + expires.toUTCString() +
            ';path=/';

        // If we're NOT explicitly on index.php, you *could* reload to let PHP auto-route.
        // Since this script is only included on index.php in your setup, this will normally
        // just set the cookie quietly and do nothing else.
        if (!window.location.pathname.match(/index\.php$/i)) {
            window.location.reload();
        }
    }

    function getCookie(name) {
        var value = '; ' + document.cookie;
        var parts = value.split('; ' + name + '=');
        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }
        return null;
    }
});
