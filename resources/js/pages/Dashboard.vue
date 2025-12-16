<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

const primaryMetrics = [
    {
        title: 'REQS',
        value: '41',
        description: 'Open requisitions',
        trend: '+5 this week',
        accent: 'from-fuchsia-600 to-purple-500',
    },
    {
        title: 'REQS LATE',
        value: '36',
        description: 'Require immediate action',
        trend: '12 critical',
        accent: 'from-rose-500 to-pink-500',
    },
    {
        title: 'SHOP ORDERS',
        value: '22',
        description: 'In progress',
        trend: '9 planned next',
        accent: 'from-indigo-500 to-blue-500',
    },
    {
        title: 'LATE TO FINISH',
        value: '8',
        description: 'Past commitment dates',
        trend: '3 blocked',
        accent: 'from-amber-500 to-orange-500',
    },
    {
        title: 'LAB P/D (H)',
        value: '373',
        description: 'Planned labor hours',
        trend: 'Next 28 days',
        accent: 'from-sky-500 to-cyan-500',
    },
    {
        title: 'WC P/D (H)',
        value: '551',
        description: 'Work center capacity',
        trend: 'Next 28 days',
        accent: 'from-emerald-500 to-green-500',
    },
    {
        title: 'LAB LOAD (H)',
        value: '64',
        description: 'Committed hours',
        trend: '87% utilization',
        accent: 'from-blue-500 to-cyan-400',
    },
    {
        title: 'WC LOAD (H)',
        value: '83',
        description: 'Scheduled production',
        trend: '78% utilization',
        accent: 'from-teal-500 to-emerald-400',
    },
];

const requisitionStatus = [
    {
        label: 'Overdue',
        quantity: 34,
        capacity: '42 hrs',
        percentage: 78,
        color: 'bg-rose-500',
    },
    {
        label: 'Next Week',
        quantity: 12,
        capacity: '17 hrs',
        percentage: 45,
        color: 'bg-amber-400',
    },
    {
        label: 'Future',
        quantity: 8,
        capacity: '9 hrs',
        percentage: 25,
        color: 'bg-emerald-400',
    },
];

const shopOrderStatus = [
    {
        label: 'Late',
        quantity: 18,
        capacity: '23 hrs',
        percentage: 70,
        color: 'bg-sky-500',
    },
    {
        label: 'Due This Week',
        quantity: 9,
        capacity: '11 hrs',
        percentage: 38,
        color: 'bg-indigo-400',
    },
    {
        label: 'Future',
        quantity: 15,
        capacity: '20 hrs',
        percentage: 60,
        color: 'bg-purple-400',
    },
];

const requisitionOverview = [
    {
        req: '211319',
        part: 'T014 - Yorkshire Tea Blend 1',
        qty: '9,930',
        start: '05/10/2018',
        due: '11/10/2018',
        status: 'Overdue',
        statusColor: 'bg-rose-500',
    },
    {
        req: '211320',
        part: 'T014 - Yorkshire Tea Blend 2',
        qty: '11,118',
        start: '05/10/2018',
        due: '15/10/2018',
        status: 'Next Week',
        statusColor: 'bg-amber-400',
    },
    {
        req: '211321',
        part: 'T014 - Yorkshire Tea Blend 3',
        qty: '41,658',
        start: '06/12/2018',
        due: '19/12/2018',
        status: 'Future',
        statusColor: 'bg-emerald-400',
    },
    {
        req: '211324',
        part: 'C012 - Casing Machine Line 1',
        qty: '63,221',
        start: '07/12/2018',
        due: '02/01/2019',
        status: 'Future',
        statusColor: 'bg-emerald-400',
    },
];

const shopOrderOverview = [
    {
        order: 'SO-1024',
        part: 'Yorkshire Tea Bags',
        qty: '10,940',
        start: '05/23/2019',
        due: '06/30/2019',
        status: 'Planned',
        statusColor: 'bg-emerald-400',
        lateToFinish: false,
    },
    {
        order: 'SO-1089',
        part: 'Tea Bag Packing',
        qty: '11,023',
        start: '06/23/2019',
        due: '07/05/2019',
        status: 'Released',
        statusColor: 'bg-sky-500',
        lateToFinish: false,
    },
    {
        order: 'SO-1182',
        part: 'Mandarin Infusion',
        qty: '25,100',
        start: '06/27/2019',
        due: '08/07/2019',
        status: 'Late',
        statusColor: 'bg-rose-500',
        lateToFinish: true,
    },
    {
        order: '2003897',
        part: 'YIBO-TBL1',
        qty: '20,000',
        start: '07/03/2019',
        due: '08/20/2019',
        status: 'Planned',
        statusColor: 'bg-emerald-400',
        lateToFinish: false,
    },
];

const labourClasses = [
    {
        label: 'LC1 - Labour Class 1',
        loadHours: 373,
        capHours: 410,
        due: '45.9%',
        status: 'warning',
    },
    {
        label: 'LC2 - Packing Line',
        loadHours: 221,
        capHours: 240,
        due: '91.3%',
        status: 'critical',
    },
];

const workCenters = [
    {
        label: 'WC - Tea Bag Packing',
        loadHours: 237,
        capHours: 280,
        due: '84%',
        status: 'warning',
    },
    {
        label: 'PAL - Palletiser',
        loadHours: 117,
        capHours: 190,
        due: '62%',
        status: 'good',
    },
    {
        label: 'CPE - Casing Machine',
        loadHours: 155,
        capHours: 190,
        due: '82%',
        status: 'warning',
    },
    {
        label: 'B1 - Blending Machine 1',
        loadHours: 160,
        capHours: 210,
        due: '76%',
        status: 'good',
    },
];

const plannerStatus = [
    {
        name: 'AND',
        planner: 'Anita Davies',
        parts: 17,
        mrp: '9 / 5 / 1',
        reqs: '5',
        so: '2',
        rl: '1',
        sol: '0',
    },
    {
        name: 'CHLL',
        planner: 'Christopher Hall',
        parts: 10,
        mrp: '7 / 2 / 1',
        reqs: '8',
        so: '5',
        rl: '1',
        sol: '1',
    },
    {
        name: 'MAWH',
        planner: 'Mark Whitwam',
        parts: 9,
        mrp: '5 / 3 / 1',
        reqs: '10',
        so: '6',
        rl: '1',
        sol: '2',
    },
];

const partStatuses = [
    {
        part: 'T014',
        description: 'Yorkshire Tea Bags',
        planner: 'AND',
        reqs: 5,
        status: 'good',
    },
    {
        part: 'B003',
        description: 'Blending Machine Line',
        planner: 'CHLL',
        reqs: 4,
        status: 'warning',
    },
    {
        part: 'BB014',
        description: 'Tea Infusion Base',
        planner: 'MAWH',
        reqs: 6,
        status: 'critical',
    },
];

const summaryCards = [
    {
        title: 'Parts',
        value: '43',
        subtext: 'Tracked items',
    },
    {
        title: 'Shortages',
        value: '12',
        subtext: 'Need replanning',
    },
    {
        title: 'MRP Action Messages Taken',
        value: '149',
        subtext: 'This week',
    },
    {
        title: 'MRP Action Messages Open',
        value: '38',
        subtext: 'Awaiting confirmation',
    },
];

const quickLinks = [
    'Manufacturing Tooling',
    'Shop Floor',
    'Subcontract Operations',
    'Advanced Planning Board',
    'MRP Action Proposal Workbench',
];

const mrpMessages = [
    {
        title: 'Demand is past due',
        count: 28,
        severity: 'critical',
    },
    {
        title: 'Plan supply is past due',
        count: 20,
        severity: 'warning',
    },
    {
        title: 'Available Qty (In Stock & Demands)',
        count: 60,
        severity: 'good',
    },
    {
        title: 'Need Orders',
        count: 12,
        severity: 'warning',
    },
];

const insights = [
    {
        title: 'Throughput',
        value: '92%',
        change: '+4.2% vs last cycle',
        description:
            'Automated lines continue to run above plan with minimal stoppages. Maintain current cadence.',
    },
    {
        title: 'Quality',
        value: '98.5%',
        change: '0 escapes',
        description:
            'QA checks cleared 445 lots this week. Critical alerts have been resolved.',
    },
    {
        title: 'Inventory Exposure',
        value: '14 days',
        change: '-3 days vs SLA',
        description:
            'Buffer levels are healthy. Expedite materials T014-07 and BB014-02.',
    },
];

const statusColors: Record<string, string> = {
    good: 'bg-emerald-500',
    warning: 'bg-amber-500',
    critical: 'bg-rose-500',
};
</script>
<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="min-h-full space-y-6 bg-gradient-to-b from-slate-50 to-white p-4 sm:p-6 lg:p-8 dark:from-slate-900 dark:to-slate-950"
        >
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-4">
                <article
                    v-for="metric in primaryMetrics"
                    :key="metric.title"
                    class="rounded-3xl bg-gradient-to-br p-5 text-white shadow-lg shadow-slate-900/10 dark:shadow-black/40"
                    :class="metric.accent"
                >
                    <p class="text-[0.7rem] font-medium uppercase tracking-[0.4em]">
                        {{ metric.title }}
                    </p>
                    <div class="mt-3 flex items-end justify-between">
                        <div>
                            <p class="text-4xl font-semibold leading-none">
                                {{ metric.value }}
                            </p>
                            <p class="mt-2 text-sm text-white/80">
                                {{ metric.description }}
                            </p>
                        </div>
                        <span
                            class="rounded-full bg-white/20 px-3 py-1 text-xs font-semibold"
                            >{{ metric.trend }}</span
                        >
                    </div>
                </article>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <section
                    class="rounded-3xl border border-slate-200 bg-white/70 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                >
                    <header class="mb-6 flex items-start justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase text-slate-500">
                                Flow Control
                            </p>
                            <h2 class="text-2xl font-semibold text-slate-900 dark:text-white">
                                Requisition & Shop Order Status
                            </h2>
                        </div>
                        <span
                            class="rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300"
                            >Live</span
                        >
                    </header>

                    <div class="space-y-8">
                        <div>
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-semibold uppercase text-fuchsia-600">
                                    Requisition Status
                                </h3>
                                <span class="text-xs text-slate-500">Qty / Load</span>
                            </div>
                            <div class="space-y-4">
                                <div
                                    v-for="status in requisitionStatus"
                                    :key="status.label"
                                    class="space-y-1"
                                >
                                    <div class="flex justify-between text-sm">
                                        <p class="font-semibold text-slate-700 dark:text-slate-100">
                                            {{ status.label }}
                                        </p>
                                        <span
                                            class="font-medium text-slate-600 dark:text-slate-300"
                                            >{{ status.quantity }} • {{ status.capacity }}</span
                                        >
                                    </div>
                                    <div class="h-[10px] w-full rounded-full bg-slate-100 dark:bg-slate-800">
                                        <div
                                            class="h-full rounded-full transition-all duration-500"
                                            :class="status.color"
                                            :style="{ width: `${status.percentage}%` }"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-semibold uppercase text-sky-600">
                                    Shop Order Status
                                </h3>
                                <span class="text-xs text-slate-500">Qty / Load</span>
                            </div>
                            <div class="space-y-4">
                                <div
                                    v-for="status in shopOrderStatus"
                                    :key="status.label"
                                    class="space-y-1"
                                >
                                    <div class="flex justify-between text-sm">
                                        <p class="font-semibold text-slate-700 dark:text-slate-100">
                                            {{ status.label }}
                                        </p>
                                        <span
                                            class="font-medium text-slate-600 dark:text-slate-300"
                                            >{{ status.quantity }} • {{ status.capacity }}</span
                                        >
                                    </div>
                                    <div class="h-[10px] w-full rounded-full bg-slate-100 dark:bg-slate-800">
                                        <div
                                            class="h-full rounded-full transition-all duration-500"
                                            :class="status.color"
                                            :style="{ width: `${status.percentage}%` }"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    class="rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                >
                    <header class="mb-6">
                        <p class="text-xs font-semibold uppercase text-slate-500">
                            Highlights
                        </p>
                        <h2 class="text-2xl font-semibold text-slate-900 dark:text-white">
                            Operational Insights
                        </h2>
                    </header>
                    <div class="space-y-5">
                        <article
                            v-for="insight in insights"
                            :key="insight.title"
                            class="rounded-2xl border border-slate-100 p-4 dark:border-slate-800"
                        >
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold uppercase text-slate-500">
                                    {{ insight.title }}
                                </p>
                                <span
                                    class="text-xs font-semibold text-emerald-500"
                                    >{{ insight.change }}</span
                                >
                            </div>
                            <p class="mt-2 text-3xl font-semibold text-slate-900 dark:text-white">
                                {{ insight.value }}
                            </p>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                                {{ insight.description }}
                            </p>
                        </article>
                    </div>
                </section>

                <section
                    class="flex flex-col justify-between rounded-3xl border border-slate-200 bg-white/80 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                >
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500">
                            Summary
                        </p>
                        <h2 class="text-2xl font-semibold text-slate-900 dark:text-white">
                            Key Indicators
                        </h2>
                    </div>
                    <div class="mt-6 grid gap-4">
                        <article
                            v-for="card in summaryCards"
                            :key="card.title"
                            class="rounded-2xl border border-slate-100 p-4 dark:border-slate-800"
                        >
                            <p class="text-sm font-semibold uppercase text-slate-500">
                                {{ card.title }}
                            </p>
                            <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-white">
                                {{ card.value }}
                            </p>
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                {{ card.subtext }}
                            </p>
                        </article>
                    </div>
                    <div class="mt-6">
                        <p class="text-xs font-semibold uppercase text-slate-500">
                            Quick Links
                        </p>
                        <ul class="mt-3 space-y-2 text-sm font-medium text-indigo-600 dark:text-indigo-400">
                            <li v-for="link in quickLinks" :key="link">
                                <span class="inline-flex items-center gap-2">
                                    <span class="inline-block h-2 w-2 rounded-full bg-indigo-400" />
                                    {{ link }}
                                </span>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>

            <div class="grid gap-6 lg:grid-cols-[2fr,1fr]">
                <div class="space-y-6">
                    <section
                        class="rounded-3xl border border-slate-200 bg-white/90 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                    >
                        <header class="mb-4 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase text-slate-500">
                                    Detail
                                </p>
                                <h2 class="text-2xl font-semibold text-slate-900 dark:text-white">
                                    Requisition Overview
                                </h2>
                            </div>
                            <button
                                class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                Export
                            </button>
                        </header>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="text-xs uppercase tracking-widest text-slate-500">
                                        <th class="pb-3">Req</th>
                                        <th class="pb-3">Part</th>
                                        <th class="pb-3">Qty</th>
                                        <th class="pb-3">Start Date</th>
                                        <th class="pb-3">Due Date</th>
                                        <th class="pb-3 text-right">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="req in requisitionOverview"
                                        :key="req.req"
                                        class="border-t border-slate-100 text-slate-700 dark:border-slate-800 dark:text-slate-200"
                                    >
                                        <td class="py-3 font-semibold">{{ req.req }}</td>
                                        <td class="py-3">
                                            <p class="font-medium">{{ req.part }}</p>
                                        </td>
                                        <td class="py-3">{{ req.qty }}</td>
                                        <td class="py-3">{{ req.start }}</td>
                                        <td class="py-3">{{ req.due }}</td>
                                        <td class="py-3 text-right">
                                            <span
                                                class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold text-white"
                                                :class="req.statusColor"
                                            >
                                                <span class="h-2 w-2 rounded-full bg-white/60" />
                                                {{ req.status }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section
                        class="rounded-3xl border border-slate-200 bg-white/90 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                    >
                        <header class="mb-4 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase text-slate-500">
                                    Execution
                                </p>
                                <h2 class="text-2xl font-semibold text-slate-900 dark:text-white">
                                    Shop Order Overview
                                </h2>
                            </div>
                            <span class="text-xs font-semibold text-slate-500"
                                >Late to Finish</span
                            >
                        </header>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="text-xs uppercase tracking-widest text-slate-500">
                                        <th class="pb-3">Order</th>
                                        <th class="pb-3">Part</th>
                                        <th class="pb-3">Qty</th>
                                        <th class="pb-3">Start</th>
                                        <th class="pb-3">Due</th>
                                        <th class="pb-3 text-right">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="order in shopOrderOverview"
                                        :key="order.order"
                                        class="border-t border-slate-100 text-slate-700 dark:border-slate-800 dark:text-slate-200"
                                    >
                                        <td class="py-3 font-semibold">{{ order.order }}</td>
                                        <td class="py-3">{{ order.part }}</td>
                                        <td class="py-3">{{ order.qty }}</td>
                                        <td class="py-3">{{ order.start }}</td>
                                        <td class="py-3">{{ order.due }}</td>
                                        <td class="py-3 text-right">
                                            <span
                                                class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold text-white"
                                                :class="order.statusColor"
                                            >
                                                <span
                                                    v-if="order.lateToFinish"
                                                    class="h-2 w-2 rounded-full bg-white/80"
                                                />
                                                {{ order.status }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="grid gap-6 lg:grid-cols-2">
                        <article
                            class="rounded-3xl border border-slate-200 bg-white/90 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                        >
                            <header class="mb-4">
                                <p class="text-xs font-semibold uppercase text-slate-500">
                                    Capacity
                                </p>
                                <h3 class="text-xl font-semibold text-slate-900 dark:text-white">
                                    Labour Class Overview
                                </h3>
                            </header>
                            <div class="space-y-4">
                                <div
                                    v-for="labour in labourClasses"
                                    :key="labour.label"
                                    class="rounded-2xl border border-slate-100 p-4 dark:border-slate-800"
                                >
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-600 dark:text-slate-200">
                                                {{ labour.label }}
                                            </p>
                                            <p class="text-xs text-slate-500">
                                                Load {{ labour.loadHours }} hrs • Capacity
                                                {{ labour.capHours }} hrs
                                            </p>
                                        </div>
                                        <span
                                            class="text-sm font-semibold text-slate-900 dark:text-white"
                                            >{{ labour.due }}</span
                                        >
                                    </div>
                                    <div class="mt-3 flex items-center gap-2 text-xs uppercase">
                                        <span
                                            class="h-2 w-2 rounded-full"
                                            :class="statusColors[labour.status]"
                                        />
                                        <span class="text-slate-500">
                                            {{ labour.status === 'critical'
                                                ? 'Capacity breach'
                                                : labour.status === 'warning'
                                                  ? 'Monitor'
                                                  : 'Healthy' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </article>

                        <article
                            class="rounded-3xl border border-slate-200 bg-white/90 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                        >
                            <header class="mb-4">
                                <p class="text-xs font-semibold uppercase text-slate-500">
                                    Work Centers
                                </p>
                                <h3 class="text-xl font-semibold text-slate-900 dark:text-white">
                                    Load Overview
                                </h3>
                            </header>
                            <div class="space-y-4">
                                <div
                                    v-for="center in workCenters"
                                    :key="center.label"
                                    class="rounded-2xl border border-slate-100 p-4 dark:border-slate-800"
                                >
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-semibold text-slate-600 dark:text-slate-200">
                                                {{ center.label }}
                                            </p>
                                            <p class="text-xs text-slate-500">
                                                Load {{ center.loadHours }} hrs • Capacity
                                                {{ center.capHours }} hrs
                                            </p>
                                        </div>
                                        <span
                                            class="text-sm font-semibold text-slate-900 dark:text-white"
                                            >{{ center.due }}</span
                                        >
                                    </div>
                                    <div class="mt-3 flex items-center gap-2 text-xs uppercase">
                                        <span
                                            class="h-2 w-2 rounded-full"
                                            :class="statusColors[center.status]"
                                        />
                                        <span class="text-slate-500">
                                            {{ center.status === 'critical'
                                                ? 'Re-plan now'
                                                : center.status === 'warning'
                                                  ? 'Tight window'
                                                  : 'On plan' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </section>

                    <section
                        class="rounded-3xl border border-slate-200 bg-white/90 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                    >
                        <header class="mb-4 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase text-slate-500">
                                    People
                                </p>
                                <h3 class="text-xl font-semibold text-slate-900 dark:text-white">
                                    Planner Status
                                </h3>
                            </div>
                            <span class="text-xs font-semibold text-slate-500"
                                >MRP / A / N / T</span
                            >
                        </header>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="text-xs uppercase tracking-widest text-slate-500">
                                        <th class="pb-3">Code</th>
                                        <th class="pb-3">Planner</th>
                                        <th class="pb-3">Parts</th>
                                        <th class="pb-3">MRP</th>
                                        <th class="pb-3">Reqs</th>
                                        <th class="pb-3">SO</th>
                                        <th class="pb-3">R/L</th>
                                        <th class="pb-3">SO/L</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="planner in plannerStatus"
                                        :key="planner.name"
                                        class="border-t border-slate-100 text-slate-700 dark:border-slate-800 dark:text-slate-200"
                                    >
                                        <td class="py-3 font-semibold">{{ planner.name }}</td>
                                        <td class="py-3">{{ planner.planner }}</td>
                                        <td class="py-3">{{ planner.parts }}</td>
                                        <td class="py-3">{{ planner.mrp }}</td>
                                        <td class="py-3">{{ planner.reqs }}</td>
                                        <td class="py-3">{{ planner.so }}</td>
                                        <td class="py-3">{{ planner.rl }}</td>
                                        <td class="py-3">{{ planner.sol }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="space-y-6">
                    <section
                        class="rounded-3xl border border-slate-200 bg-white/90 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                    >
                        <header class="mb-4">
                            <p class="text-xs font-semibold uppercase text-slate-500">
                                Inventory
                            </p>
                            <h3 class="text-xl font-semibold text-slate-900 dark:text-white">
                                Part Status
                            </h3>
                        </header>
                        <div class="space-y-4">
                            <article
                                v-for="part in partStatuses"
                                :key="part.part"
                                class="rounded-2xl border border-slate-100 p-4 dark:border-slate-800"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-600 dark:text-slate-200">
                                            {{ part.part }}
                                            <span class="text-xs font-normal text-slate-400">
                                                ({{ part.planner }})
                                            </span>
                                        </p>
                                        <p class="text-sm text-slate-500">
                                            {{ part.description }}
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-slate-600 dark:text-slate-300">
                                            {{ part.reqs }} reqs
                                        </span>
                                        <span
                                            class="h-2 w-2 rounded-full"
                                            :class="statusColors[part.status]"
                                        />
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section
                        class="rounded-3xl border border-slate-200 bg-white/90 p-6 shadow-sm backdrop-blur dark:border-slate-800 dark:bg-slate-900"
                    >
                        <header class="mb-4">
                            <p class="text-xs font-semibold uppercase text-slate-500">
                                Alerts
                            </p>
                            <h3 class="text-xl font-semibold text-slate-900 dark:text-white">
                                MRP Action Messages
                            </h3>
                        </header>
                        <div class="space-y-4">
                            <article
                                v-for="message in mrpMessages"
                                :key="message.title"
                                class="rounded-2xl border border-slate-100 p-4 dark:border-slate-800"
                            >
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-semibold text-slate-600 dark:text-slate-200">
                                        {{ message.title }}
                                    </p>
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="h-2.5 w-2.5 rounded-full"
                                            :class="statusColors[message.severity]"
                                        />
                                        <span class="text-2xl font-semibold text-slate-900 dark:text-white">
                                            {{ message.count }}
                                        </span>
                                    </div>
                                </div>
                                <div class="mt-3 h-2 rounded-full bg-slate-100 dark:bg-slate-800">
                                    <div
                                        class="h-full rounded-full"
                                        :class="statusColors[message.severity]"
                                        :style="{ width: `${Math.min(message.count, 60)}%` }"
                                    />
                                </div>
                            </article>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
