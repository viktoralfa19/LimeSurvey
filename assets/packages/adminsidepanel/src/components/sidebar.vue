<script>
import $ from 'jquery';
import _ from "lodash";
import ajaxMixin from "../mixins/runAjax.js";
import pjaxMixins from "../mixins/pjaxMixins.js";
import Questionexplorer from "./subcomponents/questionexplorer/_questionExplorer.vue";
import SidebarStateToggle from "./subcomponents/_sidebarStateToggle.vue";
import Sidemenu from "./subcomponents/_sidemenu.vue";
import Quickmenu from "./subcomponents/_quickmenu.vue";
import EventBus from "../../eventbus.js";

export default {
    name: 'SideBar',
    props: {
        landOnTab: String,
        isSideMenuElementActive: Boolean,
        activeSideMenuElement: String,
    },
    components: {
        questionexplorer: Questionexplorer,
        sidemenu: Sidemenu,
        quickmenu: Quickmenu,
        SidebarStateToggle
    },
    mixins: [ajaxMixin, pjaxMixins],
    data() {
        return {
            activeMenuIndex: 0,
            openSubpanelId: 0,
            menues: [],
            collapsed: false,
            sideBarWidth: "315",
            initialPos: { x: 0, y: 0 },
            isMouseDown: false,
            isMouseDownTimeOut: null,
            sideBarHeight: "400px",
            showLoader: false,
            loading: true,
            hiddenStateToggleDisplay: 'flex',
            smallScreenHidden: false
        };
    },
    computed: {
        useMobileView() { return window.innerWidth < 768; },
        isActive() {
            return this.$store.state.SideMenuData.isActive;
        },
        questiongroups() { return this.$store.state.questiongroups },
        sidemenus: {
            get(){return this.$store.state.sidemenus; },
            set(newValue) { this.$store.commit("updateSidemenus", newValue); }
        },
        collapsedmenus: {
            get(){return this.$store.state.collapsedmenus; },
            set(newValue) { this.$store.commit("updateCollapsedmenus", newValue); }
        },
        currentTab: {
            get() { return this.$store.state.currentTab; },
            set(tab) {
                this.$store.commit("changeCurrentTab", tab);
            }
        },
        getSideBarWidth() {
            return this.$store.getters.isCollapsed ? "98" : this.sideBarWidth;
        },
        sortedMenus() {
            return _.orderBy(
                this.menues,
                a => {
                    return parseInt(a.order || 999999);
                },
                ["asc"]
            );
        },
        showSideMenu() {
            return (
                !this.$store.getters.isCollapsed &&
                this.currentTab == "settings"
            );
        },
        showQuestionTree() {
            return (
                !this.$store.getters.isCollapsed &&
                this.currentTab == "questiontree"
            );
        },
        calculateSideBarMenuHeight() {
            let currentSideBar = this.$store.state.sideBarHeight;
            return _.min(currentSideBar, Math.floor(screen.height * 2)) + "px";
        },
        getWindowHeight() {
            return screen.height * 2 + "px";
        },
        getloaderHeight() {
            return $("#sidebar").height();
        }
    },
    methods: {
        applyLoadingState(newState) {
            this.loading = newState;
        },
        calculateHeight(self) {
            self.$store.commit(
                "changeSideBarHeight",
                $("#in_survey_common").height()
            );
        },
        changedQuestionGroupOrder() {
            const self = this;
            const onlyGroupsArray = _.map(
                this.questiongroups,
                (questiongroup, count) => {
                    const questions = _.map(
                        questiongroup.questions,
                        (question, i) => {
                            return {
                                qid: question.qid,
                                question: question.question,
                                gid: question.gid,
                                question_order: question.question_order
                            };
                        }
                    );
                    return {
                        gid: questiongroup.gid,
                        group_name: questiongroup.group_name,
                        group_order: questiongroup.group_order,
                        questions: questions
                    };
                }
            );
            this.$log.log("QuestionGroup order changed");
            this.showLoader = true;
            this.post(window.SideMenuData.updateOrderLink, {
                grouparray: onlyGroupsArray,
                surveyid: this.$store.surveyid
            }).then(
                result => {
                    self.$log.log("questiongroups updated");
                    self.$store.dispatch('getQuestions').then(() => {
                        self.showLoader = false;
                    });
                },
                error => {
                    self.$log.error("questiongroups updating error!");
                    this.post(window.SideMenuData.updateOrderLink, {
                        surveyid: this.$store.surveyid
                    }).then(()=>{
                        self.getQuestions().then(() => {
                            self.showLoader = false;
                        });
                    });
                }
            );
        },
        controlActiveLink() {
            //get current location
            let currentUrl = window.location.href;

            //Check for corresponding menuItem
            let lastMenuItemObject = false;
            _.each(this.sidemenus, (itm, i) => {
                _.each(itm.entries, (itmm, j) => {
                    lastMenuItemObject = _.endsWith(currentUrl, itmm.link)
                        ? itmm
                        : lastMenuItemObject;
                });
            });

            //check for quickmenu menuLinks
            let lastQuickMenuItemObject = false;
            _.each(this.collapsedmenus, (itm, i) => {
                _.each(itm.entries, (itmm, j) => {
                    lastQuickMenuItemObject = _.endsWith(currentUrl, itmm.link)
                        ? itmm
                        : lastQuickMenuItemObject;
                });
            });

            //check for corresponding question group object
            let lastQuestionGroupObject = false;
            _.each(this.questiongroups, (itm, i) => {
                let regTest = new RegExp(
                    'questionGroupsAdministration/view\\?surveyid=\\d*&gid=' + itm.gid +
                    '|questionGroupsAdministration/edit\\?surveyid=\\d*&gid=' + itm.gid +
                    '|questionGroupsAdministration/view/surveyid/\\d*/gid/' + itm.gid +
                    '|questionGroupsAdministration/edit/surveyid/\\d*/gid/' + itm.gid
                );
                lastQuestionGroupObject =
                    regTest.test(currentUrl) || _.endsWith(currentUrl, itm.link)
                        ? itm
                        : lastQuestionGroupObject;
            });

            //check for corresponding question group
            let lastQuestionObject = false;
            _.each(this.questiongroups, (itm, i) => {
                _.each(itm.questions, (itmm, j) => {
                    let regTest = new RegExp(
                        'questionAdministration/edit\\?questionId=' + itmm.qid +
                        '|questionAdministration/view\\?surveyid=\\d*&gid=\\d*&qid=' + itmm.qid +
                        '|questionAdministration/edit/questionId/' + itmm.qid +
                        '|questionAdministration/view/surveyid/\\d*/gid/\\d*/qid/' + itmm.qid
                    );
                    lastQuestionObject =
                        _.endsWith(currentUrl, itmm.link) ||
                        regTest.test(currentUrl)
                            ? itmm
                            : lastQuestionObject;
                    if (lastQuestionObject != false) {
                        lastQuestionGroupObject = itm;
                    }
                });
            });

            //unload every selection
            this.$store.commit("closeAllMenus");
            if (
                lastMenuItemObject != false &&
                this.$store.getters.isCollapsed != true
            )
                this.$store.commit("lastMenuItemOpen", lastMenuItemObject);
            if (
                lastQuickMenuItemObject != false &&
                this.$store.getters.isCollapsed == true
            )
                this.$store.commit("lastMenuItemOpen", lastQuickMenuItemObject);
            if (lastQuestionObject != false)
                this.$store.commit("lastQuestionOpen", lastQuestionObject);
            if (lastQuestionGroupObject != false) {
                this.$store.commit(
                    "lastQuestionGroupOpen",
                    lastQuestionGroupObject
                );
                this.$store.commit(
                    "addToQuestionGroupOpenArray",
                    lastQuestionGroupObject
                );
            }
        },
        editEntity() {
            this.setActiveMenuIndex(null, "question");
        },
        openEntity() {
            this.setActiveMenuIndex(null, "question");
        },
        setActiveMenuIndex(index) {
            this.$store.commit("lastMenuItemOpen", index);
            this.activeMenuIndex = index;
        },
        setOpenSubpanel(sId) {
            this.openSubpanelId = sId;
            this.$store.commit("lastMenuOpen", sId);
            this.$emit("menuselected", sId);
        },
        toggleCollapse() {
            this.$store.commit(
                "changeIsCollapsed",
                !this.$store.state.isCollapsed
            );
            if (this.$store.getters.isCollapsed) {
                this.sideBarWidth = "98";
            } else {
                this.sideBarWidth = this.$store.state.sidebarwidth;
            }
        },
        toggleSmallScreenHide() {
            this.smallScreenHidden = !this.smallScreenHidden;
        },
        mousedown(e) {
            if(this.useMobileView) {
                this.$store.commit("changeIsCollapsed", false);
                this.smallScreenHidden = !this.smallScreenHidden;
            }

            this.isMouseDown = this.$store.getters.isCollapsed ? false : true;
            $("#sidebar").removeClass("transition-animate-width");
            $("#pjax-content").removeClass("transition-animate-width");
        },
        mouseup(e) {
            if (this.isMouseDown) {
                this.isMouseDown = false;
                if (
                    parseInt(this.sideBarWidth) < 250 &&
                    !this.$store.getters.isCollapsed
                ) {
                    this.toggleCollapse();
                    this.$store.commit("changeSidebarwidth", "340");
                } else {
                    this.$store.commit("changeSidebarwidth", this.sideBarWidth);
                }
                $("#sidebar").addClass("transition-animate-width");
                $("#pjax-content").removeClass("transition-animate-width");
            }
        },
        mouseleave(e) {
            if (this.isMouseDown) {
                const self = this;
                this.isMouseDownTimeOut = setTimeout(() => {
                    self.mouseup(e);
                }, 1000);
            }
        },
        mousemove(e, self) {
            if (this.isMouseDown) {
                if(self.$store.getters.isRTL) {
                    if (e.screenX === 0 && e.screenY === 0) {
                        return;
                    }
                    if ((window.innerWidth-e.clientX) > screen.width / 2) {
                        this.$store.commit("maxSideBarWidth", true);
                        return;
                    }
                    self.sideBarWidth = (window.innerWidth-e.pageX) - 8 + "px";
                    this.$store.commit("changeSidebarwidth", self.sideBarWidth);
                    this.$store.commit("maxSideBarWidth", false);
                } else {
                    // prevent to emit unwanted value on dragend
                    if (e.screenX === 0 && e.screenY === 0) {
                        return;
                    }
                    if (e.clientX > screen.width / 2) {
                        this.$store.commit("maxSideBarWidth", true);
                        return;
                    }
                    self.sideBarWidth = e.pageX + 8 + "px";
                    this.$store.commit("changeSidebarwidth", self.sideBarWidth);
                    this.$store.commit("maxSideBarWidth", false);
                }
                
                window.clearTimeout(self.isMouseDownTimeOut);
                self.isMouseDownTimeOut = null;
            }
        },
        setBaseMenuPosition(entries, position) {
            switch(position) {
                case 'side' : 
                    this.sidemenus = _.orderBy(
                        entries,
                        a => {
                            return parseInt(a.order || 999999);
                        },
                        ["desc"]
                    );
                    break;
                case 'collapsed':
                    this.collapsedmenus = _.orderBy(
                        entries,
                        a => {
                            return parseInt(a.order || 999999);
                        },
                        ["desc"]
                    );
                    break;
            };
        },
        changeCurrentTab(tab) {
            if (tab === 'structure') {
                tab = 'questiontree';
            } else {
                tab = 'settings';
            }

            this.currentTab = tab;
        },
        /**
         * Filters against the active menus in sidemenu.
         * It will return the actual index of it.
         * @param {bool} isSideMenuActive Activity state of the sidemenu 
         * @return int
         */
        filterAgainstMenus(isSideMenuActive) {
            let result = 0;
            if (isSideMenuActive) {
                let sidemenu = self.sidemenus;
                result = _.findIndex(sidemenu, function(element) {
                    return element.name == self.activeSideMenuElement;
                });
            }
            return result;
        },
    },
    created() {
        const self = this;
        if(window.innerWidth < 768) {
            this.$store.commit("changeIsCollapsed", false);
        }
        self.$store.commit('setSurveyActiveState', (parseInt(this.isActive)===1));
        this.activeMenuIndex = this.$store.state.lastMenuOpen;
        if (this.$store.getters.isCollapsed) {
            this.sideBarWidth = "98";
        } else {
            this.sideBarWidth = self.$store.state.sidebarwidth;
        }
        _.each(this.$store.state.SideMenuData.basemenus, this.setBaseMenuPosition);

        // select right menu entry
        this.activeMenuIndex = this.filterAgainstMenus(this.isSideMenuActive);
    },
    mounted() {
        const self = this;

        EventBus.$on('updateSideBar', (payload) => {
            this.loading = true;
            const promises = [
                Promise.resolve()
            ];
            if (payload.updateQuestions) {
                promises.push(this.$store.dispatch('getQuestions'));
            }
            if (payload.collectMenus) {
                promises.push(this.$store.dispatch('collectMenus'));
            }
            if (payload.activeMenuIndex) {
                this.controlActiveLink();
                promises.push(Promise.resolve());
            }
            Promise.all(promises)
                .then((results) => {})
                .catch((errors) => {
                    this.$log.error(errors);
                })
                .finally(() => {
                    this.loading = false;
                })
        });

        $(document).trigger("sidebar:mounted");
        //Calculate the sidebar height and bin it to the resize event
        self.calculateHeight(self);
        window.addEventListener("resize", () => {
            self.calculateHeight(self);
        });
        
        $(document).on("pjax:send", () => {
            if (this.useMobileView && this.smallScreenHidden) {
                this.smallScreenHidden = false
            }
        });

        $(document).on("vue-sidemenu-update-link", () => {
            this.controlActiveLink();
        });

        $(document).on("vue-reload-remote", () => {
            this.$log.log('vue-reload-remote');
            this.$store.dispatch('getQuestions');
            this.$store.dispatch('collectMenus');
            this.updatePjaxLinks(this.$store);
        });

        $(document).on("vue-redraw", () => {
            this.$log.log('vue-redraw');
            this.$store.dispatch('getQuestions');
            this.$store.dispatch('collectMenus');
        });

        //control the active link
        this.controlActiveLink();
        this.updatePjaxLinks(this.$store);
        $("body").on("mousemove", event => {
            self.mousemove(event, self);
        });

        if (this.landOnTab !== '') {
           this.changeCurrentTab(this.landOnTab);
        }

        $(document).on('pjax:refresh', () => {
            this.controlActiveLink();
        });
    }
}
</script>
<template>
    <div 
        id="sidebar" 
        class="ls-flex ls-ba ls-space padding left-0 col-md-4 nofloat transition-animate-width scoped-hide-on-small" 
        :class=" smallScreenHidden ? 'toggled' : ''"
        :style="{'max-height': $store.state.inSurveyViewHeight, 'display': hiddenStateToggleDisplay}" 
        @mouseleave="mouseleave" 
        @mouseup="mouseup"
    >
        <template v-if="(useMobileView && smallScreenHidden) || !useMobileView">
            <div 
                v-if="showLoader"
                key="dragaroundLoader" 
                class="sidebar_loader" 
                :style="{width: getSideBarWidth, height: getloaderHeight}" 
            >
                <div class="ls-flex ls-flex-column fill align-content-center align-items-center">
                    <i class="fa fa-circle-o-notch fa-2x fa-spin"></i>
                </div>
            </div>
            <div 
                class="col-12 fill-height ls-space padding all-0 mainContentContainer" 
                style="height: 100%" 
                key="mainContentContainer"
            >
                <div class="mainMenu container-fluid col-12 ls-space padding right-0 fill-height">
                    <sidebar-state-toggle @collapse="toggleCollapse"/>
                    <transition name="slide-fade">
                        <sidemenu 
                            v-show="showSideMenu"
                            :loading="loading" 
                            :style="{'min-height': calculateSideBarMenuHeight}" 
                            @changeLoadingState="applyLoadingState" 
                        />
                    </transition>
                    <transition name="slide-fade">
                        <questionexplorer 
                            v-show="showQuestionTree" 
                            :loading="loading" 
                            :style="{'min-height': calculateSideBarMenuHeight}" 
                            @changeLoadingState="applyLoadingState" 
                            @openentity="openEntity" 
                            @questiongrouporder="changedQuestionGroupOrder"
                        />
                    </transition>
                    <transition name="slide-fade">
                        <quickmenu 
                            v-show="$store.getters.isCollapsed" 
                            :loading="loading" 
                            :style="{'min-height': calculateSideBarMenuHeight}" 
                            @changeLoadingState="applyLoadingState" 
                        />
                    </transition>
                </div>
            </div>
        </template>
        <div 
            v-if="(useMobileView && !smallScreenHidden) || !useMobileView"
            class="resize-handle ls-flex-column" 
            key="resizeHandle"
            :style="{'height': calculateSideBarMenuHeight, 'max-height': getWindowHeight}" 
        >
            <button 
                v-show="!$store.getters.isCollapsed" 
                class="btn btn-default" 
                @mousedown="mousedown" @click.prevent="()=>{return false;}"
            >
                <i class="fa fa-ellipsis-v" />
            </button>
        </div>
        <div class="scoped-placeholder-greyed-area" 
            v-if="(useMobileView && smallScreenHidden)" 
            @click="toggleSmallScreenHide" 
            v-html="' '"
        />
    </div>
    
</template>
<style lang="scss" scoped>
    .sidebar_loader {
        height: 100%;
        position: absolute;
        width: 100%;
        background: rgba(231, 231, 231, 0.3);
        z-index: 4501;
        box-shadow: 8px 0px 15px rgba(231, 231, 231, 0.3);
        top: 0;
    }

    .scoped-placeholder-greyed-area {
        display: none;
    }

    @media (max-width: 768px) {
        .scoped-hide-on-small {
            position: fixed;
            top: 49px;
            left:-96vw;
            width: 100vw;
            height:95vh;
            z-index: 10;
            &.toggled {
                left:0;
            }
            .mainContentContainer {
                max-width: 80vw;
                background: white;
            }
        }
        #sidebar .resize-handle {
            &>button {
                width:20px;
                background: var(--LS-admintheme-basecolor);
            }
        }
        .scoped-placeholder-greyed-area {
            display: block;
            background: rgba(125,125,125,0.2);
            height:100%;
            width:20vw;
        }
    }
</style>
