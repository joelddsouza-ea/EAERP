<script setup lang="ts">
/**
 * Masters › Outside Service Partners: washing and wheel alignment & balancing partners
 * (several per kind; the technician / advisor picks one per job) and their logins — they
 * sign in with their mobile number to see the cars sent to them and upload photos/videos.
 */
import { ref, onMounted } from 'vue'
import { PlusIcon } from '@heroicons/vue/20/solid'
import { servicePartnersApi, type ServicePartner, type OutsideKind } from '@/api/outsideWork.api'
import { useToast } from '@/composables/useToast'
import Card from '@/components/ui/Card.vue'
import Button from '@/components/ui/Button.vue'
import Badge from '@/components/ui/Badge.vue'
import Modal from '@/components/ui/Modal.vue'
import Input from '@/components/ui/Input.vue'
import Spinner from '@/components/ui/Spinner.vue'

const toast = useToast()
const partners = ref<ServicePartner[] | null>(null)
const kinds = ref<Record<OutsideKind, string>>({ washing: 'Washing', wheel_alignment: 'Wheel alignment & balancing' })

const editing = ref<ServicePartner | 'new' | null>(null)
const form = ref({ name: '', phone: '', kind: 'washing' as OutsideKind, notes: '', is_active: true })
const loginFor = ref<ServicePartner | null>(null)
const login = ref({ phone: '', password: '' })
const busy = ref(false)

async function load() {
  try {
    const data = (await servicePartnersApi.list()).data.data
    partners.value = data.partners
    kinds.value = data.kinds
  } catch (err) {
    toast.apiError(err)
    partners.value = []
  }
}

function open(p: ServicePartner | 'new') {
  editing.value = p
  form.value = p === 'new'
    ? { name: '', phone: '', kind: 'washing', notes: '', is_active: true }
    : { name: p.name, phone: p.phone ?? '', kind: p.kind, notes: p.notes ?? '', is_active: p.is_active }
}

async function save() {
  busy.value = true
  try {
    const data = { ...form.value, phone: form.value.phone || null, notes: form.value.notes || null }
    if (editing.value === 'new') await servicePartnersApi.create(data)
    else if (editing.value) await servicePartnersApi.update(editing.value.id, data)
    toast.success('Saved')
    editing.value = null
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

function openLogin(p: ServicePartner) {
  loginFor.value = p
  login.value = { phone: p.phone ?? '', password: '' }
}

async function createLogin() {
  if (!loginFor.value) return
  busy.value = true
  try {
    const res = (await servicePartnersApi.createLogin(loginFor.value.id, login.value)).data
    toast.success(res.message ?? 'Login created')
    loginFor.value = null
    await load()
  } catch (err) {
    toast.apiError(err)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Outside Service Partners</h1>
        <p class="text-sm text-gray-500">Washing and wheel alignment &amp; balancing done outside. Each partner can have a login to upload photos and videos.</p>
      </div>
      <Button @click="open('new')"><PlusIcon class="mr-1 h-4 w-4" /> Add partner</Button>
    </div>

    <div v-if="partners === null" class="flex justify-center py-12"><Spinner size="lg" /></div>

    <template v-else>
      <Card v-for="(label, kind) in kinds" :key="kind" class="mb-4">
        <template #header><h3 class="text-lg font-semibold">{{ label }}</h3></template>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="p in partners.filter((x) => x.kind === kind)" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-2" :class="p.is_active ? '' : 'opacity-60'">
            <div>
              <p class="font-medium text-gray-900">
                {{ p.name }} <Badge v-if="!p.is_active" size="sm">Off</Badge>
              </p>
              <p class="text-gray-500">
                {{ p.phone ?? 'No phone' }}{{ p.branch ? ` · ${p.branch} only` : '' }}
                · {{ p.login ? `Login: ${p.login.phone}` : 'No login' }}
              </p>
            </div>
            <div class="flex gap-3">
              <button v-if="!p.login && p.is_active" type="button" class="text-primary-700 hover:underline" @click="openLogin(p)">Create login</button>
              <button type="button" class="text-primary-700 hover:underline" @click="open(p)">Edit</button>
            </div>
          </li>
          <li v-if="!partners.some((x) => x.kind === kind)" class="py-2 text-gray-500">None yet.</li>
        </ul>
      </Card>
    </template>

    <Modal :open="editing !== null" :title="editing === 'new' ? 'Add partner' : 'Edit partner'" @close="editing = null">
      <form class="space-y-3" @submit.prevent="save">
        <Input v-model="form.name" label="Name" required />
        <label class="block text-sm">
          <span class="text-gray-600">Work</span>
          <select v-model="form.kind" class="input mt-1">
            <option v-for="(label, kind) in kinds" :key="kind" :value="kind">{{ label }}</option>
          </select>
        </label>
        <Input v-model="form.phone" label="Mobile number" type="tel" hint="WhatsApp messages about cars sent to them go to this number." />
        <Input v-model="form.notes" label="Notes" />
        <label v-if="editing !== 'new'" class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="rounded text-primary-600" /> Active (switching off also blocks their login)
        </label>
        <div class="flex justify-end gap-2 pt-2">
          <Button variant="secondary" type="button" @click="editing = null">Cancel</Button>
          <Button type="submit" :loading="busy">Save</Button>
        </div>
      </form>
    </Modal>

    <Modal :open="!!loginFor" :title="`Login for ${loginFor?.name ?? ''}`" @close="loginFor = null">
      <form class="space-y-3" @submit.prevent="createLogin">
        <Input v-model="login.phone" label="Mobile number (they sign in with it)" type="tel" required />
        <Input v-model="login.password" label="Password (at least 8 characters)" type="text" required />
        <p class="text-xs text-gray-500">Tell the partner the number and password. They see only the cars sent to them, never the customer or prices.</p>
        <div class="flex justify-end gap-2 pt-2">
          <Button variant="secondary" type="button" @click="loginFor = null">Cancel</Button>
          <Button type="submit" :loading="busy" :disabled="login.password.length < 8 || !login.phone">Create login</Button>
        </div>
      </form>
    </Modal>
  </div>
</template>
