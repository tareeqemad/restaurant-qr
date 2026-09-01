@extends('customer.status-layout', [
    'pageTitle' => 'انتهت جلسة الطاولة',
    'tone' => 'amber',
    'icon' => 'bi-hourglass-split',
    'detailIcon' => 'bi-qr-code',
])

@section('heading')انتهت جلسة هذه الطاولة@endsection
@section('body'){{ $message ?? 'أُغلقت الجلسة السابقة بعد إنهاء الطلب والدفع.' }}@endsection
@section('detail')امسح رمز QR الموجود على الطاولة مرة أخرى لفتح جلسة صحيحة وآمنة.@endsection
