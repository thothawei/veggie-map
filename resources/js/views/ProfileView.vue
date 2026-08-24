<script setup lang="ts">
import { onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';

const auth = useAuthStore();

onMounted(() => {
    if (!auth.user) {
        auth.fetchCurrentUser();
    }
});
</script>

<template>
    <div class="profile">
        <h1>個人資料</h1>
        <dl v-if="auth.user">
            <dt>姓名</dt>
            <dd>{{ auth.user.name }}</dd>
            <dt>Email</dt>
            <dd>{{ auth.user.email }}</dd>
            <dt>角色</dt>
            <dd>{{ auth.user.role === 'admin' ? '管理員' : '一般使用者' }}</dd>
        </dl>
    </div>
</template>

<style scoped>
.profile {
    max-width: 480px;
    margin: 0 auto;
    padding: 1.5rem;
}

dt {
    font-weight: 700;
    margin-top: 0.75rem;
}

dd {
    margin: 0;
}
</style>
