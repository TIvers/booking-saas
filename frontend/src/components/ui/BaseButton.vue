<script setup lang="ts">
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost'
    type?: 'button' | 'submit' | 'reset'
    loading?: boolean
    disabled?: boolean
  }>(),
  {
    variant: 'primary',
    type: 'button',
    loading: false,
    disabled: false,
  },
)
</script>

<template>
  <button
    class="base-button"
    :class="`base-button--${variant}`"
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading"
  >
    <span v-if="loading" class="base-button__spinner" aria-hidden="true"></span>
    <slot />
  </button>
</template>

<style scoped>
.base-button {
  align-items: center;
  border: 0;
  border-radius: 0.75rem;
  cursor: pointer;
  display: inline-flex;
  font: inherit;
  font-weight: 700;
  gap: 0.55rem;
  justify-content: center;
  min-height: 2.85rem;
  padding: 0.72rem 1.15rem;
  transition: background-color 140ms ease, transform 140ms ease;
}

.base-button:not(:disabled):active {
  transform: translateY(1px);
}

.base-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.base-button--primary {
  background: #204c43;
  color: #fff;
}

.base-button--primary:not(:disabled):hover {
  background: #173a33;
}

.base-button--secondary {
  background: #e8eee9;
  color: #204c43;
}

.base-button--secondary:not(:disabled):hover {
  background: #dce7df;
}

.base-button--ghost {
  background: transparent;
  color: #31574e;
}

.base-button--ghost:not(:disabled):hover {
  background: #eff3ef;
}

.base-button__spinner {
  animation: spin 850ms linear infinite;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  height: 1rem;
  width: 1rem;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
