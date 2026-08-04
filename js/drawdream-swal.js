/**
 * แจ้งเตือนแบบ SweetAlert2 — fallback เป็น alert() ถ้ายังไม่โหลด Swal
 */
(function (global) {
    function drawdreamAlert(message, icon) {
        var msg = String(message ?? '');
        var ic = icon || 'info';
        if (global.Swal && typeof global.Swal.fire === 'function') {
            global.Swal.fire({
                icon: ic,
                title: msg,
                confirmButtonText: 'ตกลง',
            });
            return;
        }
        global.alert(msg);
    }

    global.drawdreamAlert = drawdreamAlert;
})(typeof window !== 'undefined' ? window : globalThis);
