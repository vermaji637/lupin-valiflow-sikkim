<!DOCTYPE html>
<html lang="en">

{{-- Include Head --}}
@include('common.head')

<body id="page-top" class="premium-app">

    <!-- Page Wrapper -->
    <div id="wrapper">

        @auth
            <!-- Sidebar -->
            @include('common.sidebar')
            <!-- End of Sidebar -->
        @endauth

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column w-100">

            <!-- Main Content -->
            <div id="content">

                @auth
                    <!-- Topbar -->
                    @include('common.header')
                    <!-- End of Topbar -->
                @endauth

                <!-- Begin Page Content -->
                <main class="app-page">
                    @yield('content')
                </main>
                <!-- /.container-fluid -->

            </div>
            <!-- End of Main Content -->

            @auth
                <!-- Footer -->
                @include('common.footer')
                <!-- End of Footer -->
            @endauth

        </div>
        <!-- End of Content Wrapper -->

    </div>
    <!-- End of Page Wrapper -->

    @auth
        <!-- Scroll to Top Button-->
        <a class="scroll-to-top rounded" href="#page-top">
            <i class="fas fa-angle-up"></i>
        </a>
    @endauth

    @auth
        <!-- Logout Modal-->
        @include('common.logout-modal')
    @endauth

    <!-- Bootstrap core JavaScript-->
    <script src="{{asset('js/app.js')}}"></script>
    <!-- Custom scripts for all pages-->
    <script src="{{asset('admin/js/sb-admin-2.min.js')}}"></script>
    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script src="{{ asset('js/js/bootstrap.bundle.min.js') }}"></script>
   
    <script>

    document.addEventListener('DOMContentLoaded', function () {
        let formToSubmit = null;
        let esignCallback = null;
        const modalEl = document.getElementById('esignModal');
        const modal = new bootstrap.Modal(modalEl);
        const passwordInput = document.getElementById('esign_password');
        const errorBox = document.getElementById('esignError');

        function resetEsignState() {
            passwordInput.value = '';
            errorBox.innerText = '';
        }

        function openEsignModal(form) {
            formToSubmit = form || null;
            esignCallback = null;
            resetEsignState();
            modal.show();
        }

        window.runWithEsign = function (callback) {
            if (typeof callback !== 'function') {
                return;
            }

            formToSubmit = null;
            esignCallback = callback;
            resetEsignState();
            modal.show();
        };

        document.querySelectorAll('form.esign-required').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                openEsignModal(this);
            });
        });

        document.getElementById('confirmEsign').addEventListener('click', function() {
            const password = passwordInput.value;

            if (formToSubmit) {
                const hiddenField = formToSubmit.querySelector('input[name="password"]');
                if (hiddenField) {
                    hiddenField.value = password;
                }
            }

            fetch("{{ route('esign.validate') }}", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                    "X-CSRF-TOKEN": "{{ csrf_token() }}"
                },
                body: JSON.stringify({ password })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    modal.hide();

                    if (formToSubmit) {
                        const form = formToSubmit;
                        formToSubmit = null;
                        form.submit();
                        return;
                    }

                    if (esignCallback) {
                        const callback = esignCallback;
                        esignCallback = null;
                        callback(password);
                    }
                } else {
                    errorBox.innerText = data.message || "Incorrect password";
                }
            })
            .catch(() => {
                errorBox.innerText = "Something went wrong, try again.";
            });
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            if (!formToSubmit && !esignCallback) {
                return;
            }

            formToSubmit = null;
            esignCallback = null;
        });
    });

    </script>

    <script>
    function fillToday(){
          const today = new Date().toISOString().slice(0,10);
          document.querySelectorAll('input[type="date"]').forEach(el=>{ if(!el.value) el.value = today; });
        }
        function clearInputs(){
          document.querySelectorAll('input[type="text"], input[type="date"], .input').forEach(el=>{
            if(el.tagName.toLowerCase()==='textarea'){ el.value=''; el.textContent=''; }
            else if(el.type==='date'){ el.value=''; }
            else if(el.classList.contains('input')){ el.value=''; }
            else { el.value=''; }
          });
        }

        function addMultipleRows(tableId, col1Text, col2Text, countInputId) {
      let count = document.getElementById(countInputId).value;
      count = parseInt(count);

      if (isNaN(count) || count < 1) return;

      let table = document.getElementById(tableId);

      for (let i = 0; i < count; i++) {
        let row = table.insertRow(-1);
        let cell1 = row.insertCell(0);
        let cell2 = row.insertCell(1);
        let cell3 = row.insertCell(2);

        cell1.contentEditable = "true";
        cell2.contentEditable = "true";

        cell1.innerText =  " ";
        cell2.innerText =  " ";
        cell3.innerHTML = '<button type="button" class=" btn-danger " onclick="deleteRow(this)"> <i class="fa-solid fa-trash"></i></button>';
      }
    }

    function deleteRow(btn) {
      let row = btn.parentNode.parentNode;
      row.remove();
    }

    function copyCriteria(checkbox)
    {
        let row = checkbox.closest("tr");

        // Get exact criteria text
        let text = row.querySelector(".criteria").getAttribute("data-text");

        // Remove "Should be available." from text
        text = text.replace(/^Should be available\.\s*/i, '');

        let obs = row.querySelector(".observation");

        if (checkbox.checked)
        {
            obs.value = text;     // cleaned text
            obs.readOnly = true;
        }
        else
        {
            obs.value = "";
            obs.readOnly = false;
        }
    }
     @if(auth()->check())
window.loggedInUser = {
    id: "{{ auth()->id() }}",
    name: "{{ auth()->user()->full_name }}",
    email: "{{ auth()->user()->email }}",
    role: "{{ auth()->user()->getRoleNames()->first() }}"
};
@else
window.loggedInUser = null;
@endif
    function engineeringCheck(checkbox) {
    console.log(window.loggedInUser);
              let row = checkbox.closest("tr");
              let doneByInput  = row.querySelector(".done-by");

              if (checkbox.checked) {
                  // ====== API SE USER NAME LENA HAI ======
                  // Yaha par aapko backend API call lagani hogi
                  // Example: /api/get-logged-user
                  // Response me user ka name aayega

                  // For now demo ke liye static name use kar rahe hain
                let today = new Date();
            let formattedDate = today.toLocaleDateString('en-GB'); // DD/MM/YYYY

            doneByInput.value = loggedInUser.name + " - " + formattedDate;
            doneByInput.readOnly = true;
              } else {
                      doneByInput.value = "";
            doneByInput.readOnly = false;;
              }
      }


    // Force clear navbar search input to defeat Chrome autofill
    document.addEventListener("DOMContentLoaded", function() {
        const searchInput = document.getElementById("navbar-search");
        if(searchInput){
            // Immediate clear
            searchInput.value = "";
            // Force clear after short delay for stubborn autofill
            setTimeout(function() {
                searchInput.value = "";
            }, 50);
            // Final clear after longer delay
            setTimeout(function() {
                searchInput.value = "";
            }, 200);
            // Prevent autofill on focus
            searchInput.addEventListener('focus', function() {
                this.value = "";
            });
            // Prevent autofill on change
            searchInput.addEventListener('change', function() {
                if(this.value.includes('@')) {
                    this.value = "";
                }
            });
        }
    });

    </script>

    @yield('scripts')

@stack('scripts')
</body>

</html>
