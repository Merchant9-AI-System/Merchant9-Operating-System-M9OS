import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Frame } from "./Frame.vue"
export { default as FrameDescription } from "./FrameDescription.vue"
export { default as FrameFooter } from "./FrameFooter.vue"
export { default as FrameHeader } from "./FrameHeader.vue"
export { default as FramePanel } from "./FramePanel.vue"
export { default as FrameTitle } from "./FrameTitle.vue"

// Frame menyerupai ReUI Frame (reui.io/components/frame): bekas berlapik lembut yg memegang satu atau lebih panel (kad) dgn
// pengepala/pengaki pilihan. Pilihan: `variant` (default | ghost), `spacing` (sm | default | lg), `stacked` (panel bersebelahan
// bercantum, satu lajur) dan `dense` (tiada padding/jurang - panel melekat pd sempadan luar). Panel membaca keadaan Frame melalui
// atribut data (group/frame) - tiada provide/inject.
export const frameVariants = cva(
  "group/frame relative flex flex-col rounded-2xl text-card-foreground",
  {
    variants: {
      variant: {
        default: "border bg-muted/50",
        ghost: "border border-transparent bg-transparent",
      },
      spacing: {
        sm: "gap-0.5 p-0.5",
        default: "gap-1 p-1",
        lg: "gap-2 p-2",
      },
      stacked: {
        true: "gap-0",
        false: "",
      },
      dense: {
        true: "gap-0 overflow-hidden p-0",
        false: "",
      },
    },
    defaultVariants: {
      variant: "default",
      spacing: "default",
      stacked: false,
      dense: false,
    },
  },
)

export type FrameVariants = VariantProps<typeof frameVariants>
