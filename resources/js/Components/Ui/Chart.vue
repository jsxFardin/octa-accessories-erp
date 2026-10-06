<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { CHART } from '@/plugins/chartTheme';

/**
 * An ECharts canvas with the kit's chrome: Inter, hairline solid gridlines, muted axis ink,
 * a tooltip that lists every series at the pointer. Pages pass only data and series; the
 * look is decided here once, so two charts on one screen cannot disagree.
 *
 * Loaded on demand: the library is a few hundred kilobytes and only the dashboard draws.
 * The canvas is `role="img"` with a label that states the headline, and the page keeps a
 * text or table reading of the same numbers next to it — the picture is never the only copy.
 */
const props = defineProps({
    option: { type: Object, required: true },
    height: { type: Number, default: 240 },
    label: { type: String, required: true },
});

const BASE = {
    animationDuration: 300,
    textStyle: { fontFamily: 'Inter Variable, Inter, ui-sans-serif, system-ui, sans-serif', color: CHART.ink },
    color: CHART.series,
    grid: { left: 8, right: 8, top: 24, bottom: 8, containLabel: true },
    tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(15, 23, 42, 0.04)' } },
        backgroundColor: '#ffffff',
        borderColor: CHART.gridline,
        borderWidth: 1,
        padding: [8, 10],
        textStyle: { color: CHART.ink, fontSize: 12 },
        extraCssText: 'box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08); border-radius: 6px;',
    },
    legend: {
        bottom: 0,
        icon: 'roundRect',
        itemWidth: 10,
        itemHeight: 10,
        itemGap: 16,
        textStyle: { color: CHART.muted, fontSize: 12 },
    },
};

const AXIS = {
    axisLine: { show: true, lineStyle: { color: CHART.axis, width: 1 } },
    axisTick: { show: false },
    axisLabel: { color: CHART.muted, fontSize: 11, margin: 10 },
    splitLine: { show: false },
};

const VALUE_AXIS = {
    axisLine: { show: false },
    axisTick: { show: false },
    axisLabel: { color: CHART.muted, fontSize: 11 },
    splitLine: { show: true, lineStyle: { color: CHART.gridline, width: 1, type: 'solid' } },
};

function merged(option) {
    const deep = (target, source) => {
        const out = { ...target };
        for (const [key, value] of Object.entries(source ?? {})) {
            out[key] = value && typeof value === 'object' && !Array.isArray(value) && typeof out[key] === 'object' && !Array.isArray(out[key])
                ? deep(out[key], value)
                : value;
        }
        return out;
    };

    const result = deep(BASE, option);
    result.xAxis = deep(option.xAxis?.type === 'value' ? VALUE_AXIS : AXIS, option.xAxis ?? {});
    result.yAxis = deep(option.yAxis?.type === 'category' ? AXIS : VALUE_AXIS, option.yAxis ?? {});

    return result;
}

const host = ref(null);
let chart = null;
let observer = null;

onMounted(async () => {
    const [core, charts, components, renderers] = await Promise.all([
        import('echarts/core'),
        import('echarts/charts'),
        import('echarts/components'),
        import('echarts/renderers'),
    ]);

    core.use([charts.BarChart, charts.LineChart, components.GridComponent, components.TooltipComponent, components.LegendComponent, renderers.CanvasRenderer]);

    if (!host.value) return;

    chart = core.init(host.value, null, { renderer: 'canvas' });
    chart.setOption(merged(props.option));

    observer = new ResizeObserver(() => chart?.resize());
    observer.observe(host.value);
});

watch(() => props.option, (option) => chart?.setOption(merged(option), true), { deep: true });

onBeforeUnmount(() => {
    observer?.disconnect();
    chart?.dispose();
    chart = null;
});
</script>

<template>
    <div ref="host" role="img" :aria-label="label" class="w-full" :style="{ height: `${height}px` }" />
</template>
