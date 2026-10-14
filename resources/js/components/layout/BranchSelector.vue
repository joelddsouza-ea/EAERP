<script setup lang="ts">
import { computed } from 'vue'
import { Listbox, ListboxButton, ListboxOptions, ListboxOption } from '@headlessui/vue'
import { CheckIcon, ChevronUpDownIcon, BuildingOfficeIcon } from '@heroicons/vue/20/solid'
import { useBranchStore } from '@/stores/branch.store'

const branchStore = useBranchStore()

const selectedBranch = computed(() => branchStore.currentBranch)

const showAllOption = computed(() => branchStore.canViewAllBranches)

function selectBranch(branchId: number | null) {
  branchStore.setCurrentBranch(branchId)
}
</script>

<template>
  <Listbox
    v-if="branchStore.hasMultipleBranches || showAllOption"
    :model-value="branchStore.currentBranchId"
    @update:model-value="selectBranch"
  >
    <div class="relative">
      <ListboxButton
        class="relative w-full cursor-pointer rounded-lg bg-white py-2 pl-3 pr-10 text-left border border-gray-300 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent sm:text-sm"
      >
        <span class="flex items-center">
          <BuildingOfficeIcon class="h-5 w-5 text-gray-400" />
          <span class="ml-2 block truncate">
            {{ selectedBranch?.name || 'All Branches' }}
          </span>
        </span>
        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
          <ChevronUpDownIcon class="h-5 w-5 text-gray-400" />
        </span>
      </ListboxButton>

      <transition
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <ListboxOptions
          class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm"
        >
          <ListboxOption
            v-if="showAllOption"
            v-slot="{ active, selected }"
            :value="null"
          >
            <li
              :class="[
                active ? 'bg-primary-50 text-primary-900' : 'text-gray-900',
                'relative cursor-pointer select-none py-2 pl-10 pr-4',
              ]"
            >
              <span :class="[selected ? 'font-medium' : 'font-normal', 'block truncate']">
                All Branches
              </span>
              <span
                v-if="selected"
                class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary-600"
              >
                <CheckIcon class="h-5 w-5" />
              </span>
            </li>
          </ListboxOption>

          <ListboxOption
            v-for="branch in branchStore.availableBranches"
            :key="branch.id"
            v-slot="{ active, selected }"
            :value="branch.id"
          >
            <li
              :class="[
                active ? 'bg-primary-50 text-primary-900' : 'text-gray-900',
                'relative cursor-pointer select-none py-2 pl-10 pr-4',
              ]"
            >
              <span :class="[selected ? 'font-medium' : 'font-normal', 'block truncate']">
                {{ branch.name }}
              </span>
              <span
                v-if="selected"
                class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary-600"
              >
                <CheckIcon class="h-5 w-5" />
              </span>
            </li>
          </ListboxOption>
        </ListboxOptions>
      </transition>
    </div>
  </Listbox>

  <div v-else-if="selectedBranch" class="flex items-center text-sm text-gray-600">
    <BuildingOfficeIcon class="h-5 w-5 text-gray-400 mr-2" />
    {{ selectedBranch.name }}
  </div>
</template>
