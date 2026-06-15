@extends('layouts.app')

@section('title', 'Users List')

@section('content')
    <div class="container-fluid">

        <!-- Page Heading -->
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">Customer</h1>
           

        </div>
		     @include('common.alert')

    @if ($errors->any())
        <div>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
 <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Add New Company</h6>
        </div>
    <form action="{{ route('customers.store') }}" method="POST">
	<div class="card-body">
                <div class="form-group row">
        @csrf
       		
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="name">Name:</label>
        <input type="text" id="name" name="name" class="form-control form-control" value="{{ old('name') }}">
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="email">Email:</label><br>
        <input type="email" id="email" name="email" class="form-control form-control" value="{{ old('email') }}">
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="phone_number">Phone Number:</label><br>
        <input type="text" id="phone_number" name="phone_number" class="form-control form-control" value="{{ old('phone_number') }}">
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="company">Company:</label><br>
       <select id="company" name="company" class="form-control form-control">
    <option value="">Select a Company</option>
    @foreach ($companies as $id => $name)
                <option value="{{ $id }}" {{ old('company', $name) == $id ? 'selected' : '' }}>
                    {{ $name }}
                </option>
            @endforeach
</select>
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
  
		</div>
			</div>
		</div>
		<div class="card-footer">
                <button type="submit" class="btn btn-success btn-user float-right mb-3">Create Customer</button>
                <a class="btn btn-primary float-right mr-3 mb-3" href="{{ route('users.index') }}">Cancel</a>
            </div>
    </form>
</div>

    

@endsection

@section('scripts')
    
@endsection
