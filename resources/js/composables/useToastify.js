import { useToast } from 'vue-toast-notification'

export function useToastify() {
    const $toast = useToast()

    function clearToasts() {
        $toast.clear()
    }

    function showToast(message, type) {
        $toast.open({
            message,
            type,
            position: 'top-right',
            pauseOnHover: true,
        })
    }

    return {
        clearToasts,
        notifyError: (message) => showToast(message, 'error'),
        notifySuccess: (message) => showToast(message, 'success'),
        notifyInfo: (message) => showToast(message, 'info'),
        notifyWarning: (message) => showToast(message, 'warning'),
    }
}
