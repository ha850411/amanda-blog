@extends('sports.layout')
@section('body-class', 'sports-hub-page')
@push('scripts')
    <link rel="stylesheet" href="{{ asset('css/sports-hub.css') }}?v=2">
    <script src="{{ asset('js/sports-live-player.js') }}?v=gks-smart-2" defer></script>
    <script src="{{ asset('js/sports-hub.js') }}?v=4" defer></script>
@endpush
@section('content')
    @include('sports.hub')
@endsection
