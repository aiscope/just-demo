<?php

declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\User;
use App\Models\UserRecipe;
use App\Models\Calculator;
use App\Services\AnonymousUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Контроллер для работы с рецептами.
 *
 * Отвечает за вывод списка рецептов, просмотр карточки рецепта,
 * отображение избранных рецептов пользователя, а также обработку лайков
 * и проверку добавления рецепта в калькулятор для авторизованных и анонимных пользователей.
 */
class RecipesController extends Controller
{
    public function index(Request $request, $page = 1)
    {
        $perPage = config('constants.NUM_RECIPES_PERPAGE', 10);
        $currentPage = (int)$page;

        \Illuminate\Pagination\Paginator::currentPageResolver(fn() => $currentPage);

        $recipes = Recipe::withCount(['allLikes' => function ($query) {
                $query->where('is_liked', true);
            }])
            ->orderBy('all_likes_count', 'desc')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
        $recipes->withPath(route('recipes.page'));

        // Добавляем информацию о лайках и калькуляторе для каждого рецепта
        $recipes->getCollection()->transform(function ($recipe) use ($request) {
            $recipe->isLikedByCurrentUser = $this->checkIfLiked($request, $recipe->getRecipeId());
            $recipe->isInCalculator = $this->checkIfInCalculator($request, $recipe->getRecipeId());
            return $recipe;
        });

        return view('recipes.index', compact('recipes'));
    }
    
    public function show(Request $request, string $slug)
    {
        $recipeModel = Recipe::where('slug', $slug)
            ->with(['products' => function ($query) {
                $query->select(
                    'product.product_id',
                    'product.product_name',
                    'product.is_liquid',
                    'product.product_image_path'
                )->withPivot('qnt_gramm', 'qnt_ml');
            }])
            ->firstOrFail();

        $isLiked = $this->checkIfLiked($request, $recipeModel->getRecipeId());
        $isInCalculator = $this->checkIfInCalculator($request, $recipeModel->getRecipeId());

        // Формируем массив ингредиентов для Vue компонента
        $ingredients = $recipeModel->products->map(function ($product) {
            return [
                'name' => $product->product_name,
                'qnt_gramm' => $product->pivot->qnt_gramm,
                'qnt_ml' => $product->pivot->qnt_ml,
                'is_liquid' => $product->is_liquid,
                'image' => $product->product_image_path,
            ];
        })->toArray();

        return view('recipes.show', compact('recipeModel', 'isLiked', 'isInCalculator', 'ingredients'));
    }

    public function showMyRecipes(Request $request, $page = 1)
    {
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Необходимо авторизоваться');
        }

        $perPage = config('constants.NUM_RECIPES_PERPAGE', 10);
        $currentPage = (int)$page;

        \Illuminate\Pagination\Paginator::currentPageResolver(fn() => $currentPage);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Получаем рецепты, которые лайкнул пользователь
        $recipes = Recipe::whereHas('allLikes', function ($query) use ($user) {
                $query->where('user_id', $user->getId())
                    ->where('is_liked', true);
            })
            ->withCount(['allLikes' => function ($query) {
                $query->where('is_liked', true);
            }])
            ->orderBy('all_likes_count', 'desc')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);

        // Добавляем информацию о лайках и калькуляторе (все рецепты уже лайкнуты текущим пользователем)
        $recipes->getCollection()->transform(function ($recipe) use ($request) {
            $recipe->isLikedByCurrentUser = true;
            $recipe->isInCalculator = $this->checkIfInCalculator($request, $recipe->getRecipeId());
            return $recipe;
        });

        return view('recipes.my', compact('recipes'));
    }

    public function like(Request $request, int $recipeId)
    {
        $recipeModel = Recipe::findOrFail($recipeId);

        if (Auth::check()) {
            $this->handleAuthenticatedLike($recipeModel);
        } else {
            $this->handleAnonymousLike($request, $recipeId);
        }

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Проверяет, лайкнул ли пользователь рецепт
     */
    private function checkIfLiked(Request $request, int $recipeId): bool
    {
        if (Auth::check()) {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            return $user->likedRecipes()
                ->wherePivot('recipe_id', $recipeId)
                ->wherePivot('is_liked', true)
                ->exists();
        }

        // Для анонимных пользователей
        $anonUserId = AnonymousUserService::getAnonId($request);
        
        if (!$anonUserId) {
            return false;
        }

        return UserRecipe::isLikedByAnonUser($recipeId, $anonUserId);
    }

    /**
     * Обработка лайка для авторизованного пользователя
     */
    private function handleAuthenticatedLike(Recipe $recipeModel): void
    {
        $userId = Auth::id();
        $userModel = User::findOrFail($userId);

        $existingLike = $userModel->likedRecipes()
            ->wherePivot('recipe_id', $recipeModel->getRecipeId())
            ->first();

        if ($existingLike) {
            // Переключаем статус лайка
            $currentStatus = (bool) $existingLike->pivot->is_liked;
            $newStatus = !$currentStatus;

            $userModel->likedRecipes()->updateExistingPivot(
                $recipeModel->getRecipeId(),
                ['is_liked' => $newStatus]
            );
        } else {
            // Создаем новый лайк
            $userModel->likedRecipes()->attach(
                $recipeModel->getRecipeId(),
                ['is_liked' => true]
            );
        }
    }

    /**
     * Обработка лайка для анонимного пользователя
     */
    private function handleAnonymousLike(Request $request, int $recipeId): void
    {
        // Получаем или создаем постоянный ID для анонимного пользователя
        $anonUserId = AnonymousUserService::getOrCreateAnonId($request);

        $existingLike = UserRecipe::findByAnonUser($recipeId, $anonUserId);

        if ($existingLike) {
            // Переключаем статус лайка
            $existingLike->is_liked = !$existingLike->is_liked;
            $existingLike->save();
        } else {
            // Создаем новый лайк
            UserRecipe::create([
                'recipe_id'       => $recipeId,
                'is_liked'        => true,
                'is_anon_user'    => true,
                'anon_user_id'    => $anonUserId,
                'anon_session_id' => $request->session()->getId(), // Для обратной совместимости
            ]);
        }
    }

    /**
     * Проверяет, добавлен ли рецепт в калькулятор пользователем
     */
    private function checkIfInCalculator(Request $request, int $recipeId): bool
    {
        if (Auth::check()) {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            return Calculator::where('user_id', $user->getId())
                ->where('recipe_id', $recipeId)
                ->exists();
        }

        // Для анонимных пользователей
        $anonUserId = AnonymousUserService::getAnonId($request);
        
        if (!$anonUserId) {
            return false;
        }

        return Calculator::where('anon_user_id', $anonUserId)
            ->where('recipe_id', $recipeId)
            ->where('is_anon_user', true)
            ->exists();
    }
}
