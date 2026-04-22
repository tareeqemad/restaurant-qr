@csrf
<div class="row g-3">
    <div class="col-md-6"><label class="form-label">الاسم *</label>
        <input type="text" name="name" value="{{ old('name', $user->name ?? '') }}" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label">الاسم بالإنجليزية</label>
        <input type="text" name="name_en" value="{{ old('name_en', $user->name_en ?? '') }}" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">اسم المستخدم *</label>
        <input type="text" name="username" value="{{ old('username', $user->username ?? '') }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">البريد الإلكتروني</label>
        <input type="email" name="email" value="{{ old('email', $user->email ?? '') }}" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">الهاتف</label>
        <input type="text" name="phone" value="{{ old('phone', $user->phone ?? '') }}" class="form-control"></div>

    <div class="col-md-4"><label class="form-label">الدور *</label>
        <select name="role" class="form-select" required>
            @foreach($roles as $v => $label)
                <option value="{{ $v }}" @selected(old('role', $user->role ?? '')===$v)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4"><label class="form-label">المحطة</label>
        <select name="station_id" class="form-select">
            <option value="">— بدون —</option>
            @foreach($stations as $s)
                <option value="{{ $s->id }}" @selected(old('station_id', $user->station_id ?? '')==$s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4"><label class="form-label">الحالة *</label>
        <select name="status" class="form-select" required>
            <option value="active" @selected(old('status', $user->status ?? 'active')==='active')>فعال</option>
            <option value="inactive" @selected(old('status', $user->status ?? '')==='inactive')>غير فعال</option>
            <option value="suspended" @selected(old('status', $user->status ?? '')==='suspended')>معطل</option>
        </select>
    </div>

    <div class="col-md-6"><label class="form-label">كلمة المرور {{ isset($user) ? '(اتركها فارغة لعدم التغيير)' : '*' }}</label>
        <input type="password" name="password" class="form-control" @if(! isset($user)) required @endif></div>
    <div class="col-md-6"><label class="form-label">تأكيد كلمة المرور</label>
        <input type="password" name="password_confirmation" class="form-control"></div>

    <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-save me-1"></i> حفظ</button>
        <a href="{{ route('admin.users.index') }}" class="btn btn-light">إلغاء</a>
    </div>
</div>
