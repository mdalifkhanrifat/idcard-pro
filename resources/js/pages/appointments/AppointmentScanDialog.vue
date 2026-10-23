<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import UiButton from '@/components/ui/UiButton.vue';
import UiDialog from '@/components/ui/UiDialog.vue';
import { cameraProblem, numberFromQr, openCamera, qrReader, type CameraProblem } from '@/lib/appointments/scan';

/**
 * Scanning an appointment's QR code with the station's camera (5.5e): the live picture with a frame to hold the
 * code in; the first number read is handed back and the camera stops. The camera stops whenever it closes.
 */
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ scanned: [number: string] }>();
const { t } = useI18n();

const video = ref<HTMLVideoElement | null>(null);
const problem = ref<CameraProblem | null>(null);
const starting = ref(false);
let stream: MediaStream | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;
let running = 0;

function stop(): void {
    running++;
    if (timer) {
        clearTimeout(timer);
        timer = null;
    }
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

async function start(): Promise<void> {
    stop();
    const run = running;
    problem.value = null;
    starting.value = true;
    try {
        const opened = await openCamera();
        // Closed meanwhile: nothing keeps the camera
        if (run !== running) {
            opened.getTracks().forEach((track) => track.stop());
            return;
        }
        stream = opened;
        if (video.value) {
            video.value.srcObject = opened;
            await video.value.play().catch(() => {});
        }
        const read = await qrReader();
        const look = async (): Promise<void> => {
            if (run !== running || !video.value) {
                return;
            }
            const number = numberFromQr((await read(video.value)) ?? '');
            if (number && run === running) {
                stop();
                emit('scanned', number);
                open.value = false;
                return;
            }
            timer = setTimeout(look, 200);
        };
        look();
    } catch (error) {
        problem.value = cameraProblem(error);
    } finally {
        starting.value = false;
    }
}

watch(open, (value) => (value ? setTimeout(start) : stop()));
onBeforeUnmount(stop);
</script>

<template>
    <UiDialog v-model:open="open" :title="t('appointments.checkIn.scanTitle')" :description="t('appointments.checkIn.scanHint')" full-on-phone>
        <div class="space-y-3" data-scan>
            <div v-show="!problem" class="relative overflow-hidden rounded-lg bg-black">
                <video ref="video" class="aspect-[4/3] w-full object-cover" muted playsinline data-scan-video />
                <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <div class="aspect-square w-1/2 rounded-xl border-2 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]" />
                </div>
                <p v-if="starting" class="absolute inset-x-0 bottom-2 text-center text-xs text-white">{{ t('common.loading') }}</p>
            </div>
            <div v-if="problem" class="space-y-3 rounded-lg border border-line px-4 py-6 text-center text-sm text-muted" data-scan-problem>
                <p>{{ t(`appointments.checkIn.camera.${problem}`) }}</p>
                <UiButton v-if="problem !== 'insecure'" variant="secondary" size="sm" @click="start">{{ t('appointments.retry') }}</UiButton>
            </div>
        </div>
        <template #footer>
            <UiButton variant="secondary" @click="open = false">{{ t('common.close') }}</UiButton>
        </template>
    </UiDialog>
</template>
