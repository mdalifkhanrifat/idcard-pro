<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import ReportPage from './ReportPage.vue';

/** /reports/:report — a fresh page per report (its filters and columns are its own). */
const route = useRoute();
const reportKey = computed(() => String(route.params.report ?? '').replaceAll('-', '_'));
</script>

<template>
    <ReportPage :key="reportKey" :report-key="reportKey" />
</template>
