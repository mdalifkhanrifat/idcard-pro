<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { BARCODE_FORMAT_NAMES } from '@/pages/card-templates/designer/barcode';
import { QR_MAX_BYTES } from '@/pages/card-templates/designer/qr';
import type { BarcodeProblem, QrProblem } from './cards';

/**
 * Lines explaining barcodes and QR codes that will print blank (see barcodeProblems(), qrProblems()): which
 * field, the value and what the barcode type accepts, or why the QR code is empty, so the value or the
 * template can be corrected before printing.
 */
withDefaults(defineProps<{ problems: BarcodeProblem[]; qr?: QrProblem[] }>(), { qr: () => [] });
const { t, te } = useI18n();

/** Built-in fields by name; custom fields (`custom.<key>`) by their key, as the card values carry no labels. */
const fieldLabel = (field: string) => (te(`designer.fields.${field}`) ? t(`designer.fields.${field}`) : field.replace(/^custom\./, ''));
</script>

<template>
    <span v-for="problem in problems" :key="`${problem.field}|${problem.format}`" class="block" data-barcode-problem>
        <template v-if="problem.value === ''">{{ t('cards.barcode.empty', { format: BARCODE_FORMAT_NAMES[problem.format], field: fieldLabel(problem.field) }) }}</template>
        <template v-else>
            {{ t('cards.barcode.invalid', { format: BARCODE_FORMAT_NAMES[problem.format], field: fieldLabel(problem.field), value: problem.value }) }}
            {{ t(`cards.barcode.accepts.${problem.format}`) }}
        </template>
    </span>
    <span v-for="problem in qr" :key="`qr|${problem.reason}|${problem.level}|${problem.bytes}`" class="block" data-qr-problem>
        {{ problem.reason === 'empty' ? t('cards.barcode.qrEmpty') : t('cards.barcode.qrTooLong', { bytes: problem.bytes, max: QR_MAX_BYTES[problem.level], level: problem.level }) }}
    </span>
</template>
