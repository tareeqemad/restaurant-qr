{{-- Delete Confirmation Modal --}}
@props(['id', 'action', 'name' => '', 'warning' => null])

<div class="modal fade" id="{{ $id }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-top: 3px solid var(--color-danger, #EF4444);">
            <div class="modal-body text-center py-4">
                <div style="width: 56px; height: 56px; border-radius: 50%; background: var(--color-danger-bg, #FEF2F2); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
                    <i class="bi bi-trash3" style="font-size: 1.5rem; color: var(--color-danger, #EF4444);"></i>
                </div>
                <h6 class="fw-bold mb-2" style="color: var(--color-text-main, #1F2937);">تأكيد الحذف</h6>
                <p style="font-size: 0.88rem; color: var(--color-text-secondary, #3B4863); margin-bottom: 0.5rem;">
                    هل أنت متأكد من حذف <strong>{{ $name }}</strong>؟
                </p>
                @if($warning)
                    <div style="background: var(--color-warning-bg, #FFFBEB); border: 1px solid var(--color-warning-border, #FCD34D); border-radius: 8px; padding: 0.5rem 0.75rem; font-size: 0.82rem; color: var(--color-warning-text, #B45309); margin-bottom: 0.5rem;">
                        <i class="bi bi-exclamation-triangle me-1"></i>{{ $warning }}
                    </div>
                @endif
                <p style="font-size: 0.78rem; color: var(--color-danger-text, #B91C1C); margin-bottom: 0;">
                    هذا الإجراء لا يمكن التراجع عنه
                </p>
            </div>
            <div class="modal-footer justify-content-center" style="border-top: 1px solid var(--color-border-soft, #EDF1F5); padding: 0.75rem;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>إلغاء
                </button>
                <form action="{{ $action }}" method="POST" class="d-inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash3 me-1"></i>حذف
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
