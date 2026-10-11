@extends('customer.status-layout', [
    'pageTitle' => 'انتهت جلسة الطاولة',
    'tone' => 'amber',
    'icon' => 'bi-hourglass-split',
    'detailIcon' => 'bi-qr-code',
])

@section('heading')انتهت جلسة الطاولة@endsection
@section('body'){{ $message ?? 'أُغلقت الجلسة السابقة بعد إنهاء الطلب والدفع.' }}@endsection
@section('detail')امسح رمز الطاولة مرة أخرى لفتح المنيو.@endsection
