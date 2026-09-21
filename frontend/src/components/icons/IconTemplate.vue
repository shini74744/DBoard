<template>
  <component :is="icon" :size="size" :stroke-width="strokeWidth" :class="customClass" />
</template>

<script>
import { IconName } from './IconHelper';

export default {
  name: 'IconComponentName',
  components: {
    icon: IconName
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
