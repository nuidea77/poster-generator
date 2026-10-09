<script setup>
// Small inline icon set (Lucide-style strokes) so the UI needs no icon font.
defineProps({ name: { type: String, required: true }, size: { type: [Number, String], default: 18 } });

const paths = {
    sparkles: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3zM5 17l.8 2.2L8 20l-2.2.8L5 23l-.8-2.2L2 20l2.2-.8L5 17zM19 15l.6 1.4L21 17l-1.4.6L19 19l-.6-1.4L17 17l1.4-.6L19 15z',
    image: 'M3 5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5zM3 16l5-5 4 4 3-3 6 6M15.5 8.5a1.5 1.5 0 1 0 0 .01',
    video: 'M3 7a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7zM16 10l5-3v10l-5-3',
    grid: 'M3 3h8v8H3zM13 3h8v8h-8zM3 13h8v8H3zM13 13h8v8h-8z',
    book: 'M4 4a2 2 0 0 1 2-2h13v18H6a2 2 0 0 0-2 2V4zM4 20a2 2 0 0 1 2-2h13',
    plus: 'M12 5v14M5 12h14',
    x: 'M18 6 6 18M6 6l12 12',
    upload: 'M12 16V4M6 10l6-6 6 6M4 20h16',
    download: 'M12 4v12M6 10l6 6 6-6M4 20h16',
    play: 'M6 4l14 8-14 8z',
    pause: 'M7 4h3v16H7zM14 4h3v16h-3z',
    refresh: 'M21 12a9 9 0 1 1-3-6.7M21 3v6h-6',
    music: 'M9 18V5l12-2v13M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM21 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
    check: 'M20 6 9 17l-5-5',
    chevron: 'm6 9 6 6 6-6',
    trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
    edit: 'M12 20h9M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z',
    bolt: 'M13 2 3 14h8l-1 8 10-12h-8z',
    copy: 'M8 8h12v12H8zM4 16V4h12',
    clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
    layers: 'm12 2 9 5-9 5-9-5 9-5zM3 12l9 5 9-5M3 17l9 5 9-5',
    arrow: 'M5 12h14M13 6l6 6-6 6',
    menu: 'M4 6h16M4 12h16M4 18h16',
};
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="paths[name]" />
    </svg>
</template>
