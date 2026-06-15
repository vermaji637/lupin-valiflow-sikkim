@extends('auth.layouts.app')

@section('title', 'Set Password')

@section('content')
<div class="row justify-content-center">

    <div class="text-center mt-5">
        <h1 class="text-white" style="width:650px">
            <img class="pix-logo"
                 src="https://www.lupin.com/wp-content/uploads/2020/12/Logo1.png"
                 alt="Lupin"
                 style="height:80px;width:auto;">
        </h1>
    </div>

    <div class="col-xl-10 col-lg-12 col-md-9">
        <div class="card o-hidden border-0 shadow-lg my-5">
            <div class="card-body p-0">

                <div class="row">
                    <!-- Left Image -->
                    <div class="col-lg-6 d-none d-lg-block bg-login-image">
                        <img class="pix-logo"
                             src="{{ asset('admin/img/lupin1.png') }}"
                             alt="Lupin"
                             style="height:155px;width:auto;margin:0 auto;display:block;top:28%;position:relative;">
                    </div>

                    <!-- Right Form -->
                    <div class="col-lg-6">
                        <div class="p-5">
                            <div class="text-center">
                                <h1 class="h4 text-gray-900 mb-4">Set Your Password</h1>
                            </div>

                            @if (session('error'))
                                <span class="text-danger">{{ session('error') }}</span>
                            @endif

                            <form method="POST" action="{{ url('/setup-password/'.$token) }}">
                                @csrf

                                <!-- Password -->
                               <div class="form-group">
                                    <input type="password"
                                           name="password"
                                           class="form-control form-control-user @error('password') is-invalid @enderror"
                                           placeholder="New Password"
                                           required>

                                    @error('password')
                                        <span class="invalid-feedback">
                                            <strong>{{ $message }}</strong>
                                        </span>
                                    @enderror
                                    
                                    {{-- Password Policy Requirements --}}
                                    @if($policy)
                                        <div class="mt-2 small text-muted">
                                            <strong>Password Policy:</strong><br>
                                            Minimum: {{ $policy->password_min_length }} characters<br>
                                            Maximum: {{ $policy->password_max_length }} characters
                                            @if($policy->require_alphanumeric)
                                                <br>• Must contain both letters and numbers
                                            @endif
                                            @if($policy->require_special_char)
                                                <br>• Must contain at least one special character (!@#$%^&*()_+-=[]{};':"|,.<>/?)
                                            @endif
                                        </div>
                                    @endif
                                </div>


                                <!-- Confirm Password -->
                                <div class="form-group">
                                    <input type="password"
                                           name="password_confirmation"
                                           class="form-control form-control-user"
                                           placeholder="Confirm Password"
                                           required>
                                </div>

                                <button class="btn btn-primary btn-user btn-block">
                                    Set Password
                                </button>
                            </form>

                            <hr>

                            <div class="text-center">
                                <a class="small" href="{{ route('login') }}">Back to Login</a>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

</div>
@endsection
