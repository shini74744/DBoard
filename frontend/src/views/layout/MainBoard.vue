<template>

  <div class="main-board">

    <!-- 域名授权验证提示 - 如果不需要域名授权功能，移除此组件即可 -->




    <!-- 内容区域 - 路由视图 -->

    <div class="content-area">

      <router-view v-slot="{ Component }">

        <transition name="content-transition" mode="out-in" appear>

          <div class="view-wrapper" :key="$route.path">

            <component :is="Component" />

          </div>

        </transition>

      </router-view>

    </div>



    <!-- 背景装饰 -->

    <div class="background-decoration">

      <div class="floating-ball ball-1"></div>

      <div class="floating-ball ball-2"></div>

      <div class="floating-ball ball-3"></div>

    </div>

  </div>

</template>



<script>

import { ref, onMounted } from 'vue';



import DomainAuthAlert from '@/components/common/DomainAuthAlert.vue';



export default {

  name: 'MainBoard',

  components: {

    DomainAuthAlert

  },

  setup() {




    onMounted(() => {


    });





  }

};

</script>



<style lang="scss" scoped>

.main-board {

  min-height: 100vh;

  position: relative;

  overflow-x: hidden;

  z-index: 1;

}



.content-area {

  padding: 2rem 1rem;

  padding-top: 80px;



  @media (min-width: 768px) {

    padding: 2rem;

    padding-top: 90px;

  }



  @media (min-width: 1200px) {

    padding: 2rem 4rem;

    padding-top: 90px;

  }

}



.background-decoration {

  position: fixed;

  top: 0;

  left: 0;

  right: 0;

  bottom: 0;

  z-index: -1;

  overflow: hidden;

  pointer-events: none;



  @supports (-webkit-touch-callout: none) {

    display: none;

  }

}



.floating-ball {

  position: absolute;

  border-radius: 50%;

  filter: blur(60px);

  opacity: 0.3;

  mix-blend-mode: lighten;

}



.ball-1 {

  width: 600px;

  height: 600px;

  background: radial-gradient(circle at 30% 30%,

    rgba(var(--theme-color-rgb), 0.4),

    rgba(var(--theme-color-rgb), 0.1) 70%,

    transparent

  );

  top: -10%;

  left: -10%;

  animation: floatingBall1 25s infinite ease-in-out;

}



.ball-2 {

  width: 500px;

  height: 500px;

  background: radial-gradient(circle at 70% 70%,

    rgba(167, 71, 254, 0.35),

    rgba(167, 71, 254, 0.08) 70%,

    transparent

  );

  top: 40%;

  right: -5%;

  animation: floatingBall2 30s infinite ease-in-out;

}



.ball-3 {

  width: 450px;

  height: 450px;

  background: radial-gradient(circle at 50% 50%,

    rgba(55, 222, 201, 0.3),

    rgba(55, 222, 201, 0.05) 70%,

    transparent

  );

  bottom: -10%;

  left: 20%;

  animation: floatingBall3 35s infinite ease-in-out;

}



.content-transition-enter-active,

.content-transition-leave-active {

  transition: opacity 0.3s ease, transform 0.3s ease;

}



.content-transition-enter-from,

.content-transition-leave-to {

  opacity: 0;

  transform: translateY(3px);
}



@keyframes floatingBall1 {

  0%, 100% { transform: translate(0, 0) rotate(0deg); }

  25% { transform: translate(5%, 10%) rotate(90deg); }

  50% { transform: translate(2%, 5%) rotate(180deg); }

  75% { transform: translate(-3%, 8%) rotate(270deg); }

}



@keyframes floatingBall2 {

  0%, 100% { transform: translate(0, 0) rotate(0deg); }

  25% { transform: translate(-8%, -5%) rotate(-90deg); }

  50% { transform: translate(-4%, -10%) rotate(-180deg); }

  75% { transform: translate(-6%, -2%) rotate(-270deg); }

}



@keyframes floatingBall3 {

  0%, 100% { transform: translate(0, 0) rotate(0deg); }

  33% { transform: translate(6%, -8%) rotate(120deg); }

  66% { transform: translate(-4%, -4%) rotate(240deg); }

  100% { transform: translate(0, 0) rotate(360deg); }

}



.view-wrapper {

  width: 100%;

  height: 100%;

}

</style>
