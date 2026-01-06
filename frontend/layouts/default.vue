<template>
    <div class="min-h-screen flex flex-col bg-white text-gray-800">
        <Header />

        <div class="container mx-auto flex flex-1 w-full">
            <Sidebar />

            <div class="flex-1 flex flex-col">
                <div class="flex-1 p-4">
                    <slot />
                </div>

                <Footer />
            </div>
        </div>
    </div>
</template>
