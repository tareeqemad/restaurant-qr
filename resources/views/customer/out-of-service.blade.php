@extends('customer.status-layout', [
    'pageTitle' => 'الطاولة غير متاحة',
    'tone' => 'red',
    'icon' => 'bi-cone-striped',
    'detailIcon' => 'bi-person-raised-hand',
])

@section('heading')الطاولة {{ $table->number }} غير متاحة الآن@endsection
@section('body')هذه الطاولة متوقفة مؤقتاً عن استقبال الطلبات، وليست محجوزة لمجرد فتح المنيو.@endsection
@section('detail')اطلب من الجرسون اختيار طاولة متاحة أو فتح الطلب لك من شاشة الصالة.@endsection
