<template>
  <TablerIconWechat :size="size" :stroke-width="strokeWidth" :class="customClass" />
</template>

<script>
import { IconWechat } from './IconHelper';

export default {
  name: 'IconWechat',
  components: {
    TablerIconWechat: IconWechat
  },
  props: {
    size: {
      type: [Number, String],
      default: 24
    },
    strokeWidth: {
      type: [Number, String],
      default: 2
    },
    customClass: {
      type: String,
      default: ''
    }
  }
};
</script>
