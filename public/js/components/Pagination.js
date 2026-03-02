const Pagination = {
    template: `
    <nav aria-label="Page navigation example">
        <ul class="pagination justify-content-center">
            <li class="page-item" :class="localCurrentPage <= 1 ? 'disabled':''" @click="setPage(1)">
                <a class="page-link" href="#" tabindex="-1" aria-disabled="true">第一頁</a>
            </li>
            <li class="page-item" :class="localCurrentPage <= 1 ? 'disabled':''" @click="setPage('prev')">
                <a class="page-link" href="#" tabindex="-1" aria-disabled="true">上一頁</a>
            </li>
            <li class="page-item ">
                <select class="form-select" aria-label="Default select example" v-model="localCurrentPage">
                    <option v-for="page in localTotalPages" :value="page">{{ page }}</option>
                </select>
            </li>
            <li class="page-item" :class="localCurrentPage >= totalPages ? 'disabled':''" @click="setPage('next')">
                <a class="page-link" href="#">下一頁</a>
            </li>
            <li class="page-item" :class="localCurrentPage >= totalPages ? 'disabled':''" @click="setPage(totalPages)">
                <a class="page-link" href="#">最後一頁</a>
            </li>
        </ul>
    </nav>
    `,
    props: {
        currentPage: {
            type: Number,
            default: 1
        },
        totalPages: {
            type: Number,
            default: 1
        },
        limit: {
            type: Number,
            default: 5
        },
        pageChanged: {
            type: Function,
            default: () => {}
        }
    },
    data() {
        return {
            localTotalPages: this.totalPages,
            localCurrentPage: this.currentPage,
        }
    },
    watch: {
        totalPages(newVal) {
            this.localTotalPages = newVal;
        },
        currentPage(newVal) {
            this.localCurrentPage = newVal;
        },
        localCurrentPage(newVal) {
            // 若當前頁數與原先頁碼有異動, 呼叫父類別的方法來更新
            if (newVal !== this.currentPage) {
                if (this.pageChanged) {
                    this.pageChanged(newVal);
                }
            }
        }
    },
    methods: {
        setPage(action) {
            if (action === 'prev' && this.localCurrentPage > 1) {
                this.localCurrentPage--;
            } else if (action === 'next' && this.localCurrentPage < this.totalPages) {
                this.localCurrentPage++;
            } else {
                const page = parseInt(action);
                if (!isNaN(page) && page >= 1 && page <= this.totalPages) {
                    this.localCurrentPage = page;
                }
            }
        },
    },
};