<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  // Kekalkan tinggi kandungan (lalai: panel mengisi baki tinggi Frame).
  fit?: boolean
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="frame-panel"
    :class="
      cn(
        'bg-card text-card-foreground relative rounded-xl border border-border p-4 shadow-sm ring-1 ring-black/[0.03] dark:ring-white/[0.04]',
        props.fit ? 'flex-none' : 'flex-1',
        // stacked: panel bercantum dlm satu lajur - penjuru bulat hanya di hujung rangkaian (panel pertama: atas, terakhir: bawah),
        // tiada sempadan atas berganda; pengepala/pengaki bukan panel jadi tidak memutuskan rangkaian.
        'group-data-[stacked=true]/frame:shadow-none group-data-[stacked=true]/frame:ring-0 group-data-[stacked=true]/frame:[[data-slot=frame-panel]+&]:rounded-t-none group-data-[stacked=true]/frame:[[data-slot=frame-panel]+&]:border-t-0 group-data-[stacked=true]/frame:has-[+[data-slot=frame-panel]]:rounded-b-none',
        // dense: panel melekat pd sempadan luar Frame (tiada sempadan kiri/kanan/bawah sendiri), bulat (sepadan penjuru Frame) di hujung rangkaian; sempadan atas + bayang lembut memisahkan panel drpd pengepala & panel lain
        'group-data-[dense=true]/frame:rounded-2xl group-data-[dense=true]/frame:border-x-0 group-data-[dense=true]/frame:border-b-0 group-data-[dense=true]/frame:shadow-sm group-data-[dense=true]/frame:ring-0 group-data-[dense=true]/frame:[[data-slot=frame-panel]+&]:rounded-t-none group-data-[dense=true]/frame:has-[+[data-slot=frame-panel]]:rounded-b-none',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
