@extends('sports.layout')
@section('body-class', 'sports-hub-page')
@push('scripts')
    <link rel="stylesheet" href="{{ asset('css/sports-hub.css') }}?v=4">
    <script src="{{ asset('js/sports-live-player.js') }}?v=gks-smart-2" defer></script>
    <script src="{{ asset('js/sports-hub.js') }}?v=6" defer></script>
@endpush
@section('content')
    @include('sports.hub')
@endsection
