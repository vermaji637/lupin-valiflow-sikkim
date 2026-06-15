<head>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }} | @yield('title', 'Workspace')</title>

    {{-- ICON --}}
    <link rel="shortcut icon" type="image/jpg" href="{{ asset('images/icon.png') }}"/>

    <!-- Font Awesome UI KIT-->
    <!-- <script src="https://kit.fontawesome.com/f75ab26951.js" crossorigin="anonymous"></script> -->
    <link rel="stylesheet" href="/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
<!-- Font Awesome 6 -->
<!-- <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"> -->
    <link rel="stylesheet" href="/css/all.min.css">
    <!-- Custom styles for this template-->
    <link href="{{asset('css/app.css')}}" rel="stylesheet">
    <link href="{{asset('admin/css/sb-admin-2.min.css')}}" rel="stylesheet">
    <link href="{{asset('css/premium-ui.css')}}" rel="stylesheet">
    <link href="{{asset('css/css/all.min.css')}}" rel="stylesheet">
    @stack('styles')
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.tiny.cloud/1/l0ipxw3vdbs9h9h6xf2ceib3itire1rlb5b8dyautd7qy83z/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>
    <script src="{{ asset('js/js/bootstrap.bundle.min.js') }}"></script>
    <script>
        $(document).ready(function () {
            let rowCount = 1;

            // Function to add new row
            $('#addRow').on('click', function () {
                rowCount++;
                $('#itemRows').append(`
                    <tr>
                        <td>${rowCount}</td>
                        <td><input type="text" name="description[]" required></td>
                        <td><input type="text" name="make[]" required></td>
                        <td><input type="number" name="qty[]" required class="qty"></td>
                        <td><input type="number" name="rate[]" required class="rate"></td>
                        <td><input type="number" name="amount[]" readonly class="amount"></td>
                        <td><button type="button" class="removeRow btn btn-danger">Remove</button></td>
                    </tr>
                `);
            });

            // Function to remove row
            $(document).on('click', '.removeRow', function () {
                $(this).closest('tr').remove();
                rowCount--;
            });

            // Auto-calculate the amount when qty or rate changes
            $(document).on('input', '.qty, .rate', function () {
                const row = $(this).closest('tr');
                const qty = row.find('.qty').val();
                const rate = row.find('.rate').val();
                const amount = qty * rate;
                row.find('.amount').val(amount);
            });
        });
		
      if (typeof tinymce !== 'undefined' && document.querySelector('textarea')) {
      tinymce.init({
    selector: 'textarea',
    plugins: [
      // Core editing features
      'anchor', 'autolink', 'charmap', 'codesample', 'emoticons', 'image', 'link', 'lists', 'media', 'searchreplace', 'table', 'visualblocks', 'wordcount',
      // Your account includes a free trial of TinyMCE premium features
      // Try the most popular premium features until Sep 22, 2024:
      'checklist', 'mediaembed', 'casechange', 'export', 'formatpainter', 'pageembed', 'a11ychecker', 'tinymcespellchecker', 'permanentpen', 'powerpaste', 'advtable', 'advcode', 'editimage', 'advtemplate', 'ai', 'mentions', 'tinycomments', 'tableofcontents', 'footnotes', 'mergetags', 'autocorrect', 'typography', 'inlinecss', 'markdown',
    ],
    toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | link image media table mergetags | addcomment showcomments | spellcheckdialog a11ycheck typography | align lineheight | checklist numlist bullist indent outdent | emoticons charmap | removeformat',
    tinycomments_mode: 'embedded',
    tinycomments_author: 'Author name',
    mergetags_list: [
      { value: 'First.Name', title: 'First Name' },
      { value: 'Email', title: 'Email' },
    ],
    ai_request: (request, respondWith) => respondWith.string(() => Promise.reject('See docs to implement AI Assistant')),
  });
      }
       @if(!empty($policy) && !empty($policy->auto_logout_minutes) && $policy->auto_logout_minutes > 1)
	   setTimeout(() => {
        alert("Your session will expire soon due to inactivity.");
    }, ({{ $policy->auto_logout_minutes }} - 1) * 60000);
       @endif
    </script>

</head>
