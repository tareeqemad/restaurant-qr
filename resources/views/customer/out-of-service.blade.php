@extends('customer.status-layout', [
    'pageTitle' => 'الطاولة غير متاحة',
    'tone' => 'red',
    'icon' => 'bi-cone-striped',
    'detailIcon' => 'bi-person-raised-hand',
])

@section('heading')الطاولة {{ $table->number }} غير متاحة الآن@endsection
@section('body')هذه الطاولة متوقفة مؤقتاً عن استقبال الطلبات.@endsection
@section('detail')اطلب من الجرسون طاولة متاحة أو المساعدة في طلبك.@endsection
