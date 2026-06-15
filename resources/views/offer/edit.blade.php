@extends('layouts.app')

@section('title', 'Add Users')

@section('content')
   <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
   <script src="https://cdn.tiny.cloud/1/l0ipxw3vdbs9h9h6xf2ceib3itire1rlb5b8dyautd7qy83z/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>
   
<div class="container-fluid">

    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Update Propsal</h1>
        <a href="{{route('users.index')}}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm"><i
                class="fas fa-arrow-left fa-sm text-white-50"></i> Back</a>
    </div>

    {{-- Alert Messages --}}
    @include('common.alert')
   
    <!-- DataTales Example -->
    <div class="card shadow col-sm-9">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Update Propsal</h6>
        </div>
	

   <form action="{{ url('offer/update', $offer->id) }}" method="post" style="padding-bottom:50px">
    @csrf
    @method('PUT') <!-- Use PUT or PATCH for update -->
    
    <div class="card-body">
        <div class="form-group row">
            <!-- Offer Title -->
            <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
                <label for="offerTitle">Offer Title:</label>
                <input class="form-control" type="text" id="offerTitle" name="offerTitle" value="{{ old('offer_title', $offer->offer_title) }}" required>
            </div>
            
            <!-- Offer Number -->
            <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
                <label for="offerNumber">Offer No:</label>
                <input class="form-control" type="text" id="offerNumber" name="offerNumber" value="{{ $offer->offer_number }}" readonly>
            </div>

            <!-- Date -->
            <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
                <label for="date">Date:</label>
                <input class="form-control" type="text" id="date" name="date" value="{{ $offer->date }}" readonly>
            </div>

            <!-- Company -->
            <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
                <label for="company">Company:</label>
                <input class="form-control" type="text" id="company" name="company" value="{{ $offer->company }}" readonly>
            </div>

            <!-- Location -->
            <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
                <label for="location">Location:</label>
                <input class="form-control" type="text" id="location" name="location" value="{{ $offer->location }}" readonly>
            </div>

            <!-- BSS -->
            <div class="col-sm-9 mb-3 mt-3 mb-sm-0">
                <label for="bss">BSS:</label>
                <textarea class="form-control" id="bss" name="bss">{{ old('bss', $offer->bss) }}</textarea>
            </div>
        </div>
    </div>
    
    <div class="card-footer">
        <input type="submit" value="Update Offer" class="btn btn-success btn-user float-right mb-10">
       
    </div>
</form>

    </div>

</div>

@endsection

