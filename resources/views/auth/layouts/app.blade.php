<!DOCTYPE html>
<html lang="en">

{{-- Head Before AUTH--}}
@include('auth.includes.head')

<body class="auth-premium">

    <div class="auth-shell" id="wrapper">

        {{-- Content Goes Here FOR Before AUTH --}}
        @yield('content')

    </div>

    {{-- Scripts Before AUTH --}}
    @include('auth.includes.scripts')



</body>

</html>
