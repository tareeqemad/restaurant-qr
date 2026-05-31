@if($rows->isEmpty())
    <p class="text-muted mb-0">لا توجد أرصدة.</p>
@else
    <div class="list-group list-group-flush">
        @foreach($rows as $row)
            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                <span>
                    <strong>{{ $row->code }}</strong>
                    <span>{{ $row->name }}</span>
                </span>
                <strong>{{ \App\Helpers\Money::formatAccounting($row->balance) }}</strong>
            </div>
        @endforeach
    </div>
@endif
