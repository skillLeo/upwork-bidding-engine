<script setup>
import { ref, computed } from "vue";
import { ChevronDown, Pencil, Pin, Plus } from "@lucide/vue";
import { cn } from "@/lib/utils";
import { useSavedFilters } from "@/composables/useSavedFilters";
import FilterModal from "@/components/leads/FilterModal.vue";

const props = defineProps({
  activeId: { type: [Number, null], default: null },
});
const emit = defineEmits(["select"]);

const { filters, refetch } = useSavedFilters();
const menuOpen = ref(false);
const editing = ref(null); // SavedFilter object | "new" | null
const manageButton = ref(null);
const menuStyle = ref({});

const pinned = computed(() => filters.value.filter((f) => f.is_pinned));

// The trigger lives inside the filter pills' horizontally-scrolling row —
// overflow-x-auto forces overflow-y to clip too, so an absolutely
// positioned dropdown would be cut off by that row instead of floating
// above the page. Teleporting to <body> and positioning against the
// button's own rect sidesteps that entirely.
function toggleMenu() {
  if (!menuOpen.value) {
    const rect = manageButton.value.getBoundingClientRect();
    menuStyle.value = {
      top: `${rect.bottom + 6}px`,
      left: `${Math.min(rect.left, window.innerWidth - 272)}px`,
    };
  }
  menuOpen.value = !menuOpen.value;
}

function handleSaved(saved) {
  refetch();
  emit("select", saved);
  editing.value = null;
}

function handleDeleted(id) {
  refetch();
  if (props.activeId === id) emit("select", null);
  editing.value = null;
}
</script>

<template>
  <div class="thin-scroll flex items-center gap-1.5 overflow-x-auto pb-1">
    <button
      @click="emit('select', null)"
      :class="
        cn(
          'shrink-0 rounded-pill border px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-all duration-150',
          activeId === null
            ? 'border-primary bg-primary-tint text-primary shadow-sm'
            : 'border-border-strong text-text-secondary hover:bg-black/5',
        )
      "
    >
      All leads
    </button>

    <button
      v-for="filter in pinned"
      :key="filter.id"
      @click="emit('select', filter)"
      :class="
        cn(
          'group flex shrink-0 items-center gap-1 rounded-pill border px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-all duration-150',
          activeId === filter.id
            ? 'border-primary bg-primary-tint text-primary shadow-sm'
            : 'border-border-strong text-text-secondary hover:bg-black/5',
        )
      "
    >
      <Pin class="h-3 w-3 shrink-0" />
      {{ filter.name }}
      <Pencil
        v-if="activeId === filter.id"
        class="h-3 w-3 opacity-0 group-hover:opacity-100"
        @click.stop="editing = filter"
      />
    </button>

    <button
      @click="editing = 'new'"
      class="flex shrink-0 items-center gap-1 rounded-pill border border-dashed border-border-strong px-3 py-1.5 text-xs font-medium text-text-secondary hover:bg-black/5"
    >
      <Plus class="h-3 w-3" /> New filter
    </button>

    <div class="relative shrink-0">
      <button
        ref="manageButton"
        @click="toggleMenu"
        class="flex items-center gap-1 rounded-pill px-2 py-1.5 text-xs font-medium text-text-tertiary hover:bg-black/5"
      >
        Manage <ChevronDown class="h-3 w-3" />
      </button>

      <Teleport to="body">
        <template v-if="menuOpen">
          <div class="fixed inset-0 z-40" @click="menuOpen = false" />
          <div
            :style="menuStyle"
            class="fixed z-50 w-64 rounded-card border border-border bg-white py-1.5 shadow-popover"
          >
            <p v-if="filters.length === 0" class="px-3 py-2 text-xs text-text-tertiary">
              No saved filters yet.
            </p>
            <button
              v-for="filter in filters"
              :key="filter.id"
              @click="
                editing = filter;
                menuOpen = false;
              "
              class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-text-secondary hover:bg-black/5"
            >
              <span class="flex items-center gap-1.5 truncate">
                {{ filter.name }}
                <span
                  v-if="filter.is_default"
                  class="rounded-pill bg-primary-tint px-1.5 py-0.5 text-[10px] font-semibold text-primary"
                >
                  default
                </span>
              </span>
              <Pencil class="h-3.5 w-3.5 shrink-0 text-text-tertiary" />
            </button>
          </div>
        </template>
      </Teleport>
    </div>

    <FilterModal
      v-if="editing"
      :filter="editing === 'new' ? null : editing"
      @close="editing = null"
      @saved="handleSaved"
      @deleted="handleDeleted"
    />
  </div>
</template>
