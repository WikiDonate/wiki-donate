<template>
    <div class="container mx-auto bg-white-100 text-gray-800">
        <Header />
        <div class="container mx-auto mt-6 flex space-x-4">
            <Sidebar />
            <slot />
        </div>
        <Footer />
    </div>
</template>
