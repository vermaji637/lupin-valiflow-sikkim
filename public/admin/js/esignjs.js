
document.addEventListener('DOMContentLoaded', function () {

    let formToSubmit = null;
    const modalEl = document.getElementById('esignModal'); // the modal from before
    const modal = new bootstrap.Modal(modalEl);
    const passwordInput = document.getElementById('esign_password');
    const errorBox = document.getElementById('esignError');

    // Only target forms with class 'esign-required'
    document.querySelectorAll('form.esign-required').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault(); // prevent default submit
            formToSubmit = this;
            passwordInput.value = '';
            errorBox.innerText = '';
            modal.show();
        });
    });

    document.getElementById('confirmEsign').addEventListener('click', function() {
        const password = passwordInput.value;

        fetch("{{ route('esign.validate') }}", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": "{{ csrf_token() }}"
            },
            body: JSON.stringify({ password })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                modal.hide();
                formToSubmit.submit(); // finally submit the form
            } else {
                errorBox.innerText = "Incorrect password";
            }
        })
        .catch(() => {
            errorBox.innerText = "Something went wrong, try again.";
        });
    });

});

